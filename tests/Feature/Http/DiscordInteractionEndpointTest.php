<?php

namespace Tests\Feature\Http;

use App\Enums\OAuthProvider;
use App\Jobs\ProcessDiscordRsvp;
use App\Models\Game;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @covers \App\Http\Controllers\DiscordInteractionController
 * @covers \App\Http\Middleware\VerifyDiscordInteractionSignature
 *
 * End-to-end feature tests for POST /discord/interactions: a real Ed25519
 * signature over the raw request body gates entry to the controller, PING
 * acks with {type:1}, and any unsigned/tampered request 401s before reaching
 * the controller (no bypass — Discord revokes the URL on repeated bad probes).
 */
class DiscordInteractionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $secretKey;

    private string $publicKeyHex;

    protected function setUp(): void
    {
        parent::setUp();

        // Provision a fresh Ed25519 keypair per test and wire the public key
        // into config — mirrors how production reads DISCORD_BOT_PUBLIC_KEY.
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($keypair));

        config(['services.discord.bot_public_key' => $this->publicKeyHex]);
    }

    /**
     * POST a signed interaction body to the endpoint.
     *
     * Uses the raw $content string so the signature is computed over the
     * EXACT bytes the controller sees — this is the core integrity property
     * (re-serialized JSON would break the signature).
     */
    private function postSigned(string $body, ?string $overrideSignature = null, ?string $overrideTimestamp = null): TestResponse
    {
        $timestamp = $overrideTimestamp ?? '1700000000';
        $signature = $overrideSignature ?? $this->sign($body, $timestamp);

        return $this->call(
            'POST',
            '/discord/interactions',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-Signature-Ed25519' => $signature,
                'X-Signature-Timestamp' => $timestamp,
                'CONTENT_TYPE' => 'application/json',
            ]),
            $body,
        );
    }

    /**
     * Sign a body+timestamp with the test secret key, matching Discord's
     * algorithm exactly (timestamp . rawBody).
     */
    private function sign(string $body, string $timestamp): string
    {
        return bin2hex(sodium_crypto_sign_detached($timestamp.$body, $this->secretKey));
    }

    // ── PING handshake ─────────────────────────────────

    #[Test]
    public function a_signed_ping_returns_type_1_ack()
    {
        $body = '{"type":1}';

        $response = $this->postSigned($body);

        $response->assertOk();
        $response->assertJson(['type' => 1]);
    }

    #[Test]
    public function a_signed_ping_is_csrf_exempt()
    {
        // The endpoint is a stateless Discord callback, not a browser form POST.
        // A POST with no CSRF token must NOT 419 — this proves the CSRF
        // exemption in bootstrap/app.php (Paddle precedent) is wired.
        $body = '{"type":1}';

        $response = $this->postSigned($body);

        $this->assertNotEquals(419, $response->status(), 'Discord interactions endpoint must be CSRF-exempt.');
    }

    // ── Signature rejection (no bypass path) ───────────

    #[Test]
    public function an_unsigned_request_returns_401()
    {
        $body = '{"type":1}';

        // No signature/timestamp headers at all.
        $response = $this->call('POST', '/discord/interactions', [], [], [], [], $body);

        $response->assertStatus(401);
    }

    #[Test]
    public function a_tampered_body_returns_401()
    {
        // Sign one body, send a different one — the signature no longer matches.
        $signature = $this->sign('{"type":1}', '1700000000');

        $response = $this->postSigned('{"type":3}', $signature);

        $response->assertStatus(401);
    }

    #[Test]
    public function a_tampered_timestamp_returns_401()
    {
        $body = '{"type":1}';
        $signature = $this->sign($body, '1700000000');

        // Send a different timestamp than was signed.
        $response = $this->postSigned($body, $signature, '1700000001');

        $response->assertStatus(401);
    }

    #[Test]
    public function a_wrong_signature_returns_401()
    {
        // A valid-length but garbage signature.
        $garbageSignature = bin2hex(random_bytes(64));

        $response = $this->postSigned('{"type":1}', $garbageSignature);

        $response->assertStatus(401);
    }

    #[Test]
    public function missing_signature_header_returns_401()
    {
        // Timestamp present, signature absent.
        $response = $this->call(
            'POST',
            '/discord/interactions',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-Signature-Timestamp' => '1700000000',
                'CONTENT_TYPE' => 'application/json',
            ]),
            '{"type":1}',
        );

        $response->assertStatus(401);
    }

    #[Test]
    public function missing_timestamp_header_returns_401()
    {
        // Signature present (but signed with timestamp concatenated), timestamp
        // header absent → verification fails.
        $body = '{"type":1}';
        $signature = $this->sign($body, '1700000000');

        $response = $this->call(
            'POST',
            '/discord/interactions',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-Signature-Ed25519' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ]),
            $body,
        );

        $response->assertStatus(401);
    }

    // ── Raw body integrity ──────────────────────────────

    #[Test]
    public function verification_is_over_raw_bytes_not_reserialized_json()
    {
        // The signature is over the EXACT bytes sent — including whitespace.
        // A signed body with unusual formatting must verify; the same logical
        // JSON re-serialized differently would NOT. This is the raw-body gotcha.
        $body = '{  "type" : 1  }';

        $response = $this->postSigned($body);

        $response->assertOk();
        $response->assertJson(['type' => 1]);
    }

    #[Test]
    public function a_signed_ping_with_arbitrary_payload_fields_verifies()
    {
        // Discord PINGs may carry extra fields; only the raw bytes matter.
        $body = json_encode([
            'type' => 1,
            'token' => 'some-interaction-token',
            'version' => 1,
        ]);

        $response = $this->postSigned($body);

        $response->assertOk();
        $response->assertJson(['type' => 1]);
    }

    // ── Safe-default ack for unhandled types ───────────

    #[Test]
    public function a_signed_unhandled_interaction_type_returns_deferred_ack()
    {
        // S03 handles PING (type 1) and MESSAGE_COMPONENT (type 3). Other
        // interaction types get a safe DEFERRED ack so Discord does not time
        // out within the 3s window. Use type 2 APPLICATION_COMMAND (a slash
        // command) as the genuinely-unhandled case — type 3 is now handled.
        $body = json_encode([
            'type' => 2,
            'token' => 'interaction-token',
        ]);

        $response = $this->postSigned($body);

        $response->assertStatus(202);
        $response->assertJson(['type' => 5]);
    }

    #[Test]
    public function a_signed_request_with_missing_type_field_returns_deferred_ack()
    {
        // Malformed payload (no type) must not 500 — fall through to the
        // safe-default ack. T01 narrows the type to 0 (unhandled) for
        // missing/non-int input.
        $body = '{}';

        $response = $this->postSigned($body);

        $response->assertStatus(202);
        $response->assertJson(['type' => 5]);
    }

    // ── Fail-closed on misconfiguration ─────────────────

    #[Test]
    public function endpoint_401s_every_request_when_public_key_is_unset()
    {
        // No key configured → no bypass. A not-yet-provisioned bot rejects all.
        config(['services.discord.bot_public_key' => null]);

        $body = '{"type":1}';
        $signature = $this->sign($body, '1700000000');

        $response = $this->postSigned($body, $signature);

        $response->assertStatus(401);
    }

    // ── Observability ───────────────────────────────────

    #[Test]
    public function an_invalid_signature_logs_the_signature_invalid_event()
    {
        // The structured log event is the trending signal for Discord security
        // probes / key misconfiguration. Verify it fires on 401 and never
        // includes the request body (which may contain member data).
        // Log::spy() records every call without breaking unrelated log writes
        // from the global middleware stack.
        $log = Log::spy();

        $this->call('POST', '/discord/interactions', [], [], [], [], '{"type":1}')
            ->assertStatus(401);

        $log->shouldHaveReceived('info')
            ->with('discord_interaction.signature_invalid', \Mockery::on(function ($context) {
                // Only shape signals, never the body or member data.
                return is_array($context)
                    && array_key_exists('has_signature_header', $context)
                    && array_key_exists('has_timestamp_header', $context)
                    && array_key_exists('signature_length', $context)
                    && ! isset($context['body']);
            }));
    }

    // ── MESSAGE_COMPONENT: linked clicker → deferred RSVP ──

    /**
     * Build a signed MESSAGE_COMPONENT interaction body for a button click.
     */
    private function componentBody(string $customId, string $memberSnowflake, string $guildId = '999888777666555444', string $token = 'interaction-token-xyz'): string
    {
        return json_encode([
            'type' => 3,
            'token' => $token,
            'guild_id' => $guildId,
            'data' => [
                'custom_id' => $customId,
                'component_type' => 2,
            ],
            'member' => [
                'user' => ['id' => $memberSnowflake],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function a_linked_clicker_gets_a_deferred_ack_and_dispatches_the_rsvp_job()
    {
        // LINKED member: the controller must respond DEFERRED (type 5) within
        // the 3s window and dispatch ProcessDiscordRsvp carrying the game id,
        // the resolved user id, the guild id, and the interaction token. The
        // participant write belongs to the deferred job — never inline.
        Queue::fake();

        $user = User::factory()->create();
        $snowflake = '111222333444555666';
        $guildId = '999888777666555444';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => $snowflake,
        ]);

        $gameId = (string) Game::factory()->create()->id;
        $body = $this->componentBody("roundup:rsvp:{$gameId}", $snowflake, $guildId, 'tok-123');

        $response = $this->postSigned($body);

        $response->assertStatus(202);
        $response->assertJson(['type' => 5]);

        // The job is dispatched with the exact primitives the deferred path needs.
        Queue::assertPushed(ProcessDiscordRsvp::class, function (ProcessDiscordRsvp $job) use ($gameId, $user, $guildId) {
            return $job->gameId === $gameId
                && $job->userId === (string) $user->id
                && $job->guildId === $guildId
                && $job->interactionToken === 'tok-123';
        });
    }

    #[Test]
    public function a_linked_clicker_does_not_write_a_participant_inline()
    {
        // The 3s deadline is strict: the controller acks DEFERRED and the
        // participant write is deferred to the job. With Queue::fake the job
        // never runs, so no participant row is created — proving the write is
        // NOT attempted inline in the synchronous request path.
        Queue::fake();

        $user = User::factory()->create();
        $snowflake = '222333444555666777';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => $snowflake,
        ]);

        $gameId = (string) Game::factory()->create()->id;
        $body = $this->componentBody("roundup:rsvp:{$gameId}", $snowflake);

        $this->postSigned($body)->assertStatus(202);

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $gameId,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_linked_clicker_logs_the_rsvp_dispatched_event()
    {
        // discord_interaction.rsvp_dispatched {game_id, user_id, guild_id} is
        // the trending signal that the endpoint dispatched a deferred RSVP.
        Queue::fake();

        $user = User::factory()->create();
        $snowflake = '333444555666777888';
        $guildId = '111222333444555666';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => $snowflake,
        ]);

        $gameId = (string) Game::factory()->create()->id;
        $body = $this->componentBody("roundup:rsvp:{$gameId}", $snowflake, $guildId);

        $log = Log::spy();
        $this->postSigned($body)->assertStatus(202);

        $log->shouldHaveReceived('info')
            ->with('discord_interaction.rsvp_dispatched', \Mockery::on(function ($context) use ($gameId, $user, $guildId) {
                return is_array($context)
                    && ($context['game_id'] ?? null) === $gameId
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['guild_id'] ?? null) === $guildId;
            }));
    }

    // ── MESSAGE_COMPONENT: unlinked clicker → ephemeral deep-link ──

    #[Test]
    public function an_unlinked_clicker_gets_an_ephemeral_deep_link_to_the_game_page()
    {
        // UNLINKED member: no matching LinkedAccount → respond type 4 ephemeral
        // (flags 64) with a LINK button to the public game page. NO participant
        // write, NO job dispatched.
        Queue::fake();

        $gameId = (string) Game::factory()->create()->id;
        // A snowflake with no linked account.
        $body = $this->componentBody("roundup:rsvp:{$gameId}", '999999999999999999');

        $response = $this->postSigned($body);

        $response->assertOk();
        $response->assertJsonPath('type', 4);
        $response->assertJsonPath('data.flags', 64);

        // The deep-link button points at the public roundup game page.
        $response->assertJsonPath('data.components.0.components.0.url', "{$this->appUrl()}/games/{$gameId}");

        Queue::assertNotPushed(ProcessDiscordRsvp::class);
    }

    #[Test]
    public function an_unlinked_clicker_logs_the_unlinked_deep_link_event()
    {
        Queue::fake();

        $gameId = (string) Game::factory()->create()->id;
        $guildId = '444555666777888999';
        $body = $this->componentBody("roundup:rsvp:{$gameId}", '888777666555444333', $guildId);

        $log = Log::spy();
        $this->postSigned($body)->assertOk();

        $log->shouldHaveReceived('info')
            ->with('discord_interaction.unlinked_deep_link', \Mockery::on(function ($context) use ($gameId, $guildId) {
                return is_array($context)
                    && ($context['game_id'] ?? null) === $gameId
                    && ($context['guild_id'] ?? null) === $guildId;
            }));
    }

    // ── MESSAGE_COMPONENT: malformed / unknown custom_id ──

    #[Test]
    public function a_malformed_custom_id_responds_ephemeral_and_never_dispatches()
    {
        // An unknown button custom_id (not roundup:rsvp:*) must never dispatch
        // a job. Respond gracefully (ephemeral type 4) so Discord sees a valid
        // ACK and the clicker gets a helpful message.
        Queue::fake();

        $body = $this->componentBody('something:else', '111222333444555666');

        $response = $this->postSigned($body);

        $response->assertOk();
        $response->assertJsonPath('type', 4);
        $response->assertJsonPath('data.flags', 64);

        Queue::assertNotPushed(ProcessDiscordRsvp::class);
    }

    #[Test]
    public function a_missing_custom_id_responds_ephemeral_and_never_dispatches()
    {
        // No data.custom_id at all — same graceful-ephemeral contract.
        Queue::fake();

        $body = json_encode([
            'type' => 3,
            'token' => 'tok',
            'guild_id' => '123',
            'data' => ['component_type' => 2],
            'member' => ['user' => ['id' => '111222333444555666']],
        ]);

        $response = $this->postSigned($body);

        $response->assertOk();
        $response->assertJsonPath('type', 4);
        $response->assertJsonPath('data.flags', 64);

        Queue::assertNotPushed(ProcessDiscordRsvp::class);
    }

    #[Test]
    public function an_rsvp_custom_id_with_no_game_id_responds_ephemeral_and_never_dispatches()
    {
        // Edge case: custom_id is exactly the prefix with no game id trailing
        // it — malformed, must not dispatch.
        Queue::fake();

        $body = $this->componentBody('roundup:rsvp:', '111222333444555666');

        $response = $this->postSigned($body);

        $response->assertOk();
        $response->assertJsonPath('type', 4);
        $response->assertJsonPath('data.flags', 64);

        Queue::assertNotPushed(ProcessDiscordRsvp::class);
    }

    #[Test]
    public function a_d_m_interaction_resolves_identity_from_user_id()
    {
        // DM-context interactions carry the user under `user.id` rather than
        // `member.user.id`. A linked clicker in a DM must still dispatch the
        // RSVP job — the resolver honours both member and user surfaces.
        Queue::fake();

        $user = User::factory()->create();
        $snowflake = '555666777888999000';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => $snowflake,
        ]);

        $gameId = (string) Game::factory()->create()->id;
        $body = json_encode([
            'type' => 3,
            'token' => 'dm-tok',
            'data' => ['custom_id' => "roundup:rsvp:{$gameId}", 'component_type' => 2],
            // DM context: no `member`, user carried at top level.
            'user' => ['id' => $snowflake],
        ]);

        $response = $this->postSigned($body);

        $response->assertStatus(202);
        $response->assertJson(['type' => 5]);

        Queue::assertPushed(ProcessDiscordRsvp::class, function (ProcessDiscordRsvp $job) use ($gameId, $user) {
            return $job->gameId === $gameId && $job->userId === (string) $user->id;
        });
    }

    /**
     * The configured app.url (the base for deep-link URLs), trailing-slash trimmed.
     */
    private function appUrl(): string
    {
        $url = config('app.url');

        return is_string($url) ? rtrim($url, '/') : '';
    }
}
