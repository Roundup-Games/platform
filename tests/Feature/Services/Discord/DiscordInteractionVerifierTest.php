<?php

namespace Tests\Feature\Services\Discord;

use App\Services\Discord\DiscordInteractionVerifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @covers \App\Services\Discord\DiscordInteractionVerifier
 *
 * Verifies the Ed25519 signature primitive in isolation. This is the
 * highest-risk unfamiliar tech in S03 (Discord revokes the interactions URL on
 * repeated bad probes), so the crypto is unit-tested exhaustively with a
 * real generated Ed25519 keypair — no mocking of sodium.
 */
class DiscordInteractionVerifierTest extends TestCase
{
    /**
     * Generate a fresh Ed25519 keypair for testing and return the verifier
     * configured with that public key, plus a signer closure.
     *
     * @return array{0: DiscordInteractionVerifier, 1: string, 2: \Closure}
     *                                                                      [verifier, publicKeyHex, sign(body,timestamp)->hexSignature]
     */
    private function makeVerifierAndSigner(): array
    {
        // sodium_crypto_sign_keypair() generates an Ed25519 keypair. The
        // secret key (64 bytes) is the first half; the public key (32 bytes)
        // is the second half — which is exactly what Discord publishes.
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $publicKeyHex = bin2hex($publicKey);

        $verifier = new DiscordInteractionVerifier($publicKeyHex);

        $sign = static function (string $body, string $timestamp) use ($secretKey): string {
            // Discord signs timestamp . rawBody — string concatenation.
            $signature = sodium_crypto_sign_detached(
                $timestamp.$body,
                $secretKey,
            );

            return bin2hex($signature);
        };

        return [$verifier, $publicKeyHex, $sign];
    }

    // ── Happy path ──────────────────────────────────────

    #[Test]
    public function verify_returns_true_for_a_correctly_signed_request()
    {
        [$verifier, , $sign] = $this->makeVerifierAndSigner();

        $body = '{"type":1}';
        $timestamp = (string) time();
        $signature = $sign($body, $timestamp);

        $this->assertTrue($verifier->verify($body, $signature, $timestamp));
    }

    #[Test]
    public function verify_accepts_arbitrary_raw_body_bytes_including_whitespace()
    {
        // Discord sends the exact JSON bytes; verification must be byte-exact.
        // A body with unusual whitespace (as Discord may send) must verify.
        [$verifier, , $sign] = $this->makeVerifierAndSigner();

        $body = '{ "type": 3, "data": { "custom_id": "roundup:rsvp:abc" } }';
        $timestamp = '1700000000';
        $signature = $sign($body, $timestamp);

        $this->assertTrue($verifier->verify($body, $signature, $timestamp));
    }

    // ── Tamper detection ────────────────────────────────

    #[Test]
    public function verify_returns_false_when_body_is_tampered_after_signing()
    {
        // The core security property: re-encoding or altering the body breaks
        // the signature. This is why we verify against raw bytes, not
        // re-serialized JSON.
        [$verifier, , $sign] = $this->makeVerifierAndSigner();

        $timestamp = '1700000000';
        $signature = $sign('{"type":1}', $timestamp);

        $this->assertFalse($verifier->verify('{"type":3}', $signature, $timestamp));
    }

    #[Test]
    public function verify_returns_false_when_timestamp_is_tampered_after_signing()
    {
        [$verifier, , $sign] = $this->makeVerifierAndSigner();

        $body = '{"type":1}';
        $signature = $sign($body, '1700000000');

        $this->assertFalse($verifier->verify($body, $signature, '1700000001'));
    }

    #[Test]
    public function verify_returns_false_when_signed_with_a_different_key()
    {
        // A signature from another bot's key must never verify — proves the
        // verifier is pinning to the configured public key, not accepting any.
        [$verifier] = $this->makeVerifierAndSigner();

        // Generate a second, independent keypair and sign with it.
        $otherKeypair = sodium_crypto_sign_keypair();
        $otherSecret = sodium_crypto_sign_secretkey($otherKeypair);

        $body = '{"type":1}';
        $timestamp = '1700000000';
        $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$body, $otherSecret));

        $this->assertFalse($verifier->verify($body, $signature, $timestamp));
    }

    // ── Malformed input (fail closed, never throw) ─────

    #[Test]
    public function verify_returns_false_for_empty_signature()
    {
        [$verifier, , $sign] = $this->makeVerifierAndSigner();

        $body = '{"type":1}';
        $timestamp = '1700000000';
        $sign($body, $timestamp); // sign but discard — test empty sig path

        $this->assertFalse($verifier->verify($body, '', $timestamp));
    }

    #[Test]
    public function verify_returns_false_for_non_hex_signature()
    {
        [$verifier] = $this->makeVerifierAndSigner();

        // Odd-length / invalid hex → hex2bin returns false.
        $this->assertFalse($verifier->verify('{"type":1}', 'zzzz', '1700000000'));
    }

    #[Test]
    public function verify_returns_false_for_wrong_length_signature()
    {
        [$verifier] = $this->makeVerifierAndSigner();

        // A valid 32-byte (not 64-byte) hex signature — wrong length.
        $shortSig = bin2hex(random_bytes(32));

        $this->assertFalse($verifier->verify('{"type":1}', $shortSig, '1700000000'));
    }

    #[Test]
    public function verify_returns_false_for_empty_timestamp()
    {
        [$verifier, , $sign] = $this->makeVerifierAndSigner();

        $body = '{"type":1}';
        $signature = $sign($body, '1700000000');

        $this->assertFalse($verifier->verify($body, $signature, ''));
    }

    // ── Fail-closed on misconfiguration ─────────────────

    #[Test]
    public function verify_returns_false_when_public_key_is_unset()
    {
        // The bypass guard: an unset key must NEVER pass. A not-yet-provisioned
        // or misconfigured bot 401s every request rather than accepting any.
        $verifier = new DiscordInteractionVerifier(null);

        $this->assertFalse($verifier->verify('{"type":1}', bin2hex(random_bytes(64)), '1700000000'));
    }

    #[Test]
    public function verify_returns_false_when_public_key_is_empty_string()
    {
        $verifier = new DiscordInteractionVerifier('');

        $this->assertFalse($verifier->verify('{"type":1}', bin2hex(random_bytes(64)), '1700000000'));
    }

    #[Test]
    public function verify_returns_false_when_configured_public_key_is_invalid_hex()
    {
        // A garbage configured key should fail closed, not throw.
        $verifier = new DiscordInteractionVerifier('not-a-valid-hex-key');

        $this->assertFalse($verifier->verify('{"type":1}', bin2hex(random_bytes(64)), '1700000000'));
    }

    // ── Reads from config when no key passed ────────────

    #[Test]
    public function verifier_reads_public_key_from_config_when_not_overridden()
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKeyHex = bin2hex(sodium_crypto_sign_publickey($keypair));

        config(['services.discord.bot_public_key' => $publicKeyHex]);

        $verifier = new DiscordInteractionVerifier;

        $body = '{"type":1}';
        $timestamp = '1700000000';
        $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$body, $secretKey));

        $this->assertTrue($verifier->verify($body, $signature, $timestamp));
    }
}
