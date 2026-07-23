<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VerifyDiscordInteractionSignature;
use App\Jobs\ProcessDiscordRsvp;
use App\Models\User;
use App\Services\Discord\DiscordCardRenderer;
use App\Services\Discord\DiscordIdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Discord HTTP Interactions at POST /discord/interactions.
 *
 * Discord's stateless interactions model: every request is signed with
 * Ed25519 (verified by {@see VerifyDiscordInteractionSignature}
 * before this controller runs) and must be acknowledged within 3 seconds.
 *
 * Interaction types (S03-relevant):
 *   1 PING             — Discord's handshake probe; required to register the
 *                        interactions URL in the Developer Portal. Ack with
 *                        {type: 1}. (T01)
 *   3 MESSAGE_COMPONENT — a button click (custom_id roundup:rsvp:{gameId}).
 *                         (T02) Resolve identity → linked clickers get a
 *                         DEFERRED ack + a queued RSVP job through the same
 *                         participant pipeline as a web RSVP; unlinked
 *                         clickers get an ephemeral deep-link to RSVP on
 *                         roundup web. Malformed/unknown custom_ids get a
 *                         graceful ephemeral message and NEVER dispatch a job.
 *
 * The 3-second ACK deadline is strict: this controller MUST return a response
 * synchronously. Anything that could exceed the window (participant writes,
 * Discord REST calls) is deferred to {@see ProcessDiscordRsvp} — never
 * attempted inline. The only inline DB read is the identity lookup, which is
 * a single indexed query well inside the deadline.
 */
class DiscordInteractionController extends Controller
{
    /**
     * Button custom_id prefix the roundup RSVP button uses. Namespaced so
     * roundup interactions never collide with another bot's component ids.
     * Mirrors {@see DiscordCardRenderer::buildComponents()}.
     */
    private const RSVP_CUSTOM_ID_PREFIX = 'roundup:rsvp:';

    /**
     * Response type 4: CHANNEL_MESSAGE_WITH_SOURCE — a new message shown only
     * to the clicker when combined with the ephemeral flag.
     */
    private const TYPE_CHANNEL_MESSAGE = 4;

    /**
     * Response type 5: DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE — acks the
     * interaction immediately ("Bot is thinking…") so Discord sees a valid
     * response within the 3s window; the job resolves it later.
     */
    private const TYPE_DEFERRED = 5;

    /**
     * Discord ephemeral flag (1 << 6): the message is visible only to the
     * clicker, not the whole channel. Used for the unlinked deep-link and
     * every graceful "we couldn't process that" response.
     */
    private const FLAG_EPHEMERAL = 64;

    public function __construct(
        private readonly DiscordIdentityResolver $identityResolver,
    ) {}

    /**
     * Handle an inbound Discord interaction.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $type = $this->interactionType($request);

        return match ($type) {
            // PING (type 1): Discord's registration handshake. Ack with {type:1}.
            1 => $this->handlePing(),
            // MESSAGE_COMPONENT (type 3): a button click. Resolve identity and
            // fork into deferred-RSVP (linked) or ephemeral deep-link (unlinked).
            3 => $this->handleMessageComponent($request),
            // Other interaction types are not part of S03's surface. Ack with
            // DEFERRED so Discord does not time out within the 3s window.
            default => $this->handleUnknown($request, $type),
        };
    }

    // ── PING ────────────────────────────────────────────

    /**
     * Acknowledge a PING: respond {type: 1}.
     */
    private function handlePing(): JsonResponse
    {
        Log::info('discord_interaction.ping', [
            // PING carries no payload beyond type=1; the structured event is the
            // trending signal that the endpoint is alive and Discord is probing it.
        ]);

        return response()->json(['type' => 1]);
    }

    // ── MESSAGE_COMPONENT (button click) ────────────────

    /**
     * Handle a MESSAGE_COMPONENT interaction (a button click).
     *
     * Within the 3s deadline: parse the button custom_id, resolve the
     * clicker's identity, and fork:
     *   - LINKED clicker   → DEFERRED ack + dispatch the RSVP job (the job
     *                        writes through the SAME participant pipeline as
     *                        a web RSVP — one source of truth).
     *   - UNLINKED clicker → ephemeral deep-link to RSVP on roundup web (no
     *                        participant write; value-framed, non-blocking).
     *
     * A malformed or unknown custom_id gets a graceful ephemeral message and
     * NEVER dispatches a job. No inline DB write beyond the identity read.
     */
    private function handleMessageComponent(Request $request): JsonResponse
    {
        $customId = $this->customId($request);
        $gameId = $this->gameIdFromCustomId($customId);

        // Malformed or unknown button → graceful ephemeral, no dispatch.
        // Never 500; never dispatch a job for a request we can't route.
        if ($gameId === null) {
            Log::info('discord_interaction.malformed_custom_id', [
                'has_custom_id' => $customId !== null,
            ]);

            return $this->ephemeralMessage(
                "This button couldn't be processed. Try RSVPing on roundup, or tap the game's link for the latest details."
            );
        }

        $guildId = $this->guildId($request);
        $user = $this->identityResolver->resolveBySnowflake(
            $this->memberSnowflake($request)
        );

        // LINKED: defer + dispatch the RSVP job. The job carries primitives
        // (ids + the interaction token) per the queued-job convention so it
        // serializes cleanly and re-fetches models in handle().
        if ($user instanceof User) {
            $interactionToken = $this->interactionToken($request);

            // Pass the User's PK (a UUID string id), NOT its route key: the
            // User model's getRouteKey() yields its slug, but the job contract
            // and game_participants.user_id both reference the UUID id, not
            // the slug. The deferred job's User::find()/GameParticipant::create()
            // downstream key on id.
            ProcessDiscordRsvp::dispatch(
                $gameId,
                (string) $user->id,
                $guildId,
                $interactionToken,
            );

            Log::info('discord_interaction.rsvp_dispatched', [
                'game_id' => $gameId,
                'user_id' => $user->id,
                'guild_id' => $guildId,
            ]);

            // DEFERRED ack so Discord sees a valid response within the 3s
            // window. The job resolves the interaction later via @original.
            return response()->json(['type' => self::TYPE_DEFERRED], 202);
        }

        // UNLINKED: ephemeral deep-link to RSVP on roundup web. No participant
        // write — the clicker needs a roundup account first. Value-framed and
        // non-blocking so the card stays a low-friction surface.
        Log::info('discord_interaction.unlinked_deep_link', [
            'game_id' => $gameId,
            'guild_id' => $guildId,
        ]);

        return $this->unlinkedDeepLink($gameId);
    }

    /**
     * Build the ephemeral deep-link response for an unlinked clicker.
     *
     * A single LINK button to the public game page (the SAME target the card's
     * "View on roundup" button uses) plus value-framed copy explaining that
     * linking once unlocks in-Discord RSVP. Ephemeral so it's private to the
     * clicker and never clutters the channel.
     */
    private function unlinkedDeepLink(string $gameId): JsonResponse
    {
        $url = $this->gameDeepLinkUrl($gameId);

        return response()->json([
            'type' => self::TYPE_CHANNEL_MESSAGE,
            'data' => [
                'flags' => self::FLAG_EPHEMERAL,
                'content' => "You're almost on the roster. RSVP on roundup to grab your seat — link your Discord account there once and this button RSVPs you straight from Discord next time.",
                'components' => [
                    [
                        'type' => 1, // ACTION_ROW
                        'components' => [
                            [
                                'type' => 2, // BUTTON
                                'style' => 5, // LINK
                                'label' => 'RSVP on roundup',
                                'url' => $url,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The public roundup game page URL for a deep-link button.
     *
     * Matches the card renderer's deep-link shape ({appUrl}/games/{id}); the
     * bare path 302-redirects to the visitor's preferred locale via the
     * catch-all route, so one URL serves every locale.
     */
    private function gameDeepLinkUrl(string $gameId): string
    {
        $baseUrl = is_string(config('app.url')) ? rtrim(config('app.url'), '/') : '';

        return $baseUrl.'/games/'.$gameId;
    }

    /**
     * A minimal ephemeral message response (type 4, flags 64) with text only.
     * Used for graceful failure handling where there is no deep-link to show.
     */
    private function ephemeralMessage(string $content): JsonResponse
    {
        return response()->json([
            'type' => self::TYPE_CHANNEL_MESSAGE,
            'data' => [
                'flags' => self::FLAG_EPHEMERAL,
                'content' => $content,
            ],
        ]);
    }

    // ── Safe-default for unhandled interaction types ────

    /**
     * Safe-default acknowledgment for interaction types S03 does not handle.
     *
     * Returns a DEFERRED response (type 5) so Discord sees a valid ACK within
     * the 3s window and does not mark the interaction as failed.
     */
    private function handleUnknown(Request $request, int $type): JsonResponse
    {
        Log::info('discord_interaction.unhandled_type', [
            'type' => $type,
        ]);

        return response()->json(['type' => self::TYPE_DEFERRED], 202);
    }

    // ── Payload extraction (narrows mixed request input) ──

    /**
     * Extract the interaction type from the payload, narrowing the mixed input.
     *
     * Returns 0 (unhandled) for malformed/missing type so the match falls
     * through to the safe-default ack rather than throwing.
     */
    private function interactionType(Request $request): int
    {
        $type = $request->input('type');

        return is_int($type) ? $type : 0;
    }

    /**
     * The button custom_id from a MESSAGE_COMPONENT interaction, or null.
     */
    private function customId(Request $request): ?string
    {
        $customId = $request->input('data.custom_id');

        return is_string($customId) && $customId !== '' ? $customId : null;
    }

    /**
     * Parse a roundup RSVP custom_id (`roundup:rsvp:{gameId}`) into the game id,
     * or null when the custom_id is malformed or doesn't carry an RSVP.
     *
     * The game id is the roundup Game string PK (a UUID); we return it as-is
     * for the job/identity lookups and never validate it exists inline (the
     * deferred job re-fetches the Game and handles missing/invalid ids).
     */
    private function gameIdFromCustomId(?string $customId): ?string
    {
        if ($customId === null) {
            return null;
        }

        if (! str_starts_with($customId, self::RSVP_CUSTOM_ID_PREFIX)) {
            return null;
        }

        $gameId = substr($customId, strlen(self::RSVP_CUSTOM_ID_PREFIX));

        return $gameId === '' ? null : $gameId;
    }

    /**
     * The clicker's Discord user snowflake from the interaction member.
     *
     * Guild interactions carry the member under `member.user.id`; DM
     * interactions carry it directly under `user.id`. We honour both so the
     * resolver works in either context (cards are guild-channel posts, but
     * the resolver should not assume the only delivery surface).
     */
    private function memberSnowflake(Request $request): string
    {
        $memberUserId = $request->input('member.user.id');
        if (is_string($memberUserId) && $memberUserId !== '') {
            return $memberUserId;
        }

        $dmUserId = $request->input('user.id');

        return is_string($dmUserId) && $dmUserId !== '' ? $dmUserId : '';
    }

    /**
     * The guild id the interaction originated in, or empty string for a DM.
     */
    private function guildId(Request $request): string
    {
        $guildId = $request->input('guild_id');

        return is_string($guildId) && $guildId !== '' ? $guildId : '';
    }

    /**
     * The interaction token (webhook URL credential). Required by the deferred
     * job to PATCH the @original response. Discord issues one per interaction,
     * valid for 15 minutes.
     */
    private function interactionToken(Request $request): string
    {
        $token = $request->input('token');

        return is_string($token) && $token !== '' ? $token : '';
    }
}
