<?php

namespace App\Services\Discord;

/**
 * Verifies Discord HTTP Interactions signatures (Ed25519).
 *
 * Discord signs EVERY interaction request with Ed25519 over
 * (timestamp . rawBody) using the bot's public key (from the Developer
 * Portal → General Information → "Public Key") and runs automated security
 * probes. Failing verification repeatedly gets the interactions URL revoked
 * (system DM + email alert), so this must:
 *   1. Run on every request — there is no bypass path.
 *   2. Fail CLOSED — any unset/invalid config or malformed header rejects.
 *
 * The algorithm (per Discord docs, cross-confirmed by discord-interactions-js):
 *   publicKey = hex_decode(BOT_PUBLIC_KEY)        # 32 bytes
 *   signature = hex_decode(X-Signature-Ed25519)   # 64 bytes
 *   message   = X-Signature-Timestamp . rawBody   # raw string, NOT re-serialized JSON
 *   valid     = sodium_crypto_sign_verify_detached(signature, message, publicKey)
 *
 * Uses ext-sodium's verified `sodium_crypto_sign_verify_detached` primitive.
 * Never hand-roll the curve. Guards lengths/`hex2bin` returning false BEFORE
 * calling sodium, because malformed input throws {@see \SodiumException} which
 * would otherwise be an unguarded 500.
 *
 * CRITICAL: verification is over the RAW request body bytes Discord sent
 * (`Request::getContent()`). Re-encoding via json_decode → json_encode changes
 * byte order/whitespace and verification FAILS. The middleware supplies the
 * raw body; the controller may json_decode afterward.
 */
class DiscordInteractionVerifier
{
    private const PUBLIC_KEY_BYTES = 32;

    private const SIGNATURE_BYTES = 64;

    private string $publicKeyHex;

    public function __construct(?string $publicKeyHex = null)
    {
        $configured = config('services.discord.bot_public_key');
        $this->publicKeyHex = $publicKeyHex ?? (is_string($configured) ? $configured : '');
    }

    /**
     * Verify a Discord interaction signature.
     *
     * @param  string  $rawBody  The exact bytes Discord sent (Request::getContent()).
     * @param  string  $signatureHex  The X-Signature-Ed25519 header value (hex).
     * @param  string  $timestamp  The X-Signature-Timestamp header value.
     * @return bool True iff the signature is valid. False on ANY malformed input
     *              or unset/misconfigured key (fail closed — never throws).
     */
    public function verify(string $rawBody, string $signatureHex, string $timestamp): bool
    {
        // Fail closed on misconfiguration: an unset/empty public key must never
        // pass verification (that would be a bypass path). A test config or a
        // not-yet-provisioned bot 401s every request rather than accepting any.
        if ($this->publicKeyHex === '') {
            return false;
        }

        $publicKey = @hex2bin($this->publicKeyHex);
        $signature = @hex2bin($signatureHex);

        // hex2bin returns false on non-hex input (odd length / invalid chars).
        // Guard lengths so sodium never receives a malformed buffer.
        if ($publicKey === false
            || $signature === false
            || strlen($publicKey) !== self::PUBLIC_KEY_BYTES
            || strlen($signature) !== self::SIGNATURE_BYTES
            || $timestamp === ''
        ) {
            return false;
        }

        // sodium_crypto_sign_verify_detached is a constant-time verified
        // primitive; returns bool. The length guards above make the
        // SodiumException path unreachable, but suppress defensively.
        try {
            return sodium_crypto_sign_verify_detached(
                $signature,
                $timestamp.$rawBody,
                $publicKey,
            );
        } catch (\SodiumException) {
            return false;
        }
    }
}
