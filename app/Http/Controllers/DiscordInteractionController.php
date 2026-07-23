<?php

namespace App\Http\Controllers;

use App\Enums\ParticipantStatus;
use App\Http\Middleware\VerifyDiscordInteractionSignature;
use App\Jobs\ProcessDiscordLeave;
use App\Jobs\ProcessDiscordRsvp;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Discord\DiscordIdentityResolver;
use App\Services\Discord\DiscordRsvpMenuContext;
use App\Services\Discord\DiscordRsvpMenuRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Discord HTTP Interactions at POST /discord/interactions.
 *
 * Discord's stateless interactions model: every request is signed with
 * Ed25519 (verified by {@see VerifyDiscordInteractionSignature} before this
 * controller runs) and must be acknowledged within 3 seconds.
 *
 * Interaction types:
 *   1 PING             — Discord's handshake probe; required to register the
 *                        interactions URL. Ack with {type: 1}.
 *   3 MESSAGE_COMPONENT — a button click. Three custom_id actions:
 *      roundup:rsvp:{gameId}  → the card's "My seat" button: an immediate
 *                               per-clicker ephemeral menu showing the
 *                               clicker's current roster state + the relevant
 *                               action (Join / Leave). A state read — fast,
 *                               returns inline (type 4, no defer).
 *      roundup:join:{gameId}  → the menu's Join button: DEFERRED + the queued
 *                               join job (ProcessDiscordRsvp — the same
 *                               participant pipeline as a web RSVP).
 *      roundup:leave:{gameId} → the menu's Leave button: DEFERRED + the queued
 *                               leave job (ProcessDiscordLeave — mirrors the
 *                               web leaveGame path).
 *
 * The 3-second ACK deadline is strict. The RSVP menu is a single fast query
 * (Game + participants) and returns inline; the join/leave writes are deferred
 * (they touch the participant pipeline, which is too slow for the window).
 *
 * Unlinked clickers always get an ephemeral deep-link to RSVP on roundup web —
 * the per-user menu is meaningless without a linked identity.
 */
class DiscordInteractionController extends Controller
{
    /**
     * Shared custom_id prefix for all roundup Discord buttons. Namespaced so
     * roundup interactions never collide with another bot's component ids.
     * Format: roundup:{action}:{gameId}. Mirrors DiscordCardRenderer /
     * DiscordRsvpMenuRenderer button builders.
     */
    private const CUSTOM_ID_PREFIX = 'roundup:';

    /**
     * The action verbs carried in custom_ids, routed to their handlers.
     */
    private const ACTION_MENU = 'rsvp';

    private const ACTION_JOIN = 'join';

    private const ACTION_LEAVE = 'leave';

    private const TYPE_CHANNEL_MESSAGE = 4;

    private const TYPE_DEFERRED = 5;

    private const FLAG_EPHEMERAL = 64;

    public function __construct(
        private readonly DiscordIdentityResolver $identityResolver,
        private readonly DiscordRsvpMenuRenderer $menuRenderer,
    ) {}

    /**
     * Handle an inbound Discord interaction.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $type = $this->interactionType($request);

        return match ($type) {
            1 => $this->handlePing(),
            3 => $this->handleMessageComponent($request),
            default => $this->handleUnknown($request, $type),
        };
    }

    // ── PING ────────────────────────────────────────────

    private function handlePing(): JsonResponse
    {
        Log::info('discord_interaction.ping');

        return response()->json(['type' => 1]);
    }

    // ── MESSAGE_COMPONENT (button click) ────────────────

    /**
     * Parse the button's custom_id, resolve the clicker's identity, and route
     * to the action handler. Malformed/unknown custom_ids get a graceful
     * ephemeral message and never dispatch a job.
     */
    private function handleMessageComponent(Request $request): JsonResponse
    {
        $customId = $this->customId($request);
        $parsed = $this->parseCustomId($customId);

        if ($parsed === null) {
            Log::info('discord_interaction.malformed_custom_id', [
                'has_custom_id' => $customId !== null,
            ]);

            return $this->ephemeralMessage(
                "This button couldn't be processed. Try RSVPing on roundup, or tap the game's link for the latest details."
            );
        }

        ['action' => $action, 'gameId' => $gameId] = $parsed;

        $guildId = $this->guildId($request);
        $user = $this->identityResolver->resolveBySnowflake(
            $this->memberSnowflake($request)
        );

        return match ($action) {
            self::ACTION_MENU => $this->handleMenuRequest($gameId, $user, $guildId),
            self::ACTION_JOIN => $this->handleDeferredAction(
                $gameId, $user, $guildId, $this->interactionToken($request), ProcessDiscordRsvp::class, 'join_dispatched'
            ),
            self::ACTION_LEAVE => $this->handleDeferredAction(
                $gameId, $user, $guildId, $this->interactionToken($request), ProcessDiscordLeave::class, 'leave_dispatched'
            ),
            default => $this->ephemeralMessage(
                "This button couldn't be processed. Try RSVPing on roundup, or tap the game's link for the latest details."
            ),
        };
    }

    // ── RSVP menu (the card's "My seat" button) ─────────

    /**
     * Return the per-clicker ephemeral RSVP menu — the clicker's current roster
     * state + the action relevant to them. This is a fast state read (one
     * Game + participants query), so it returns inline within the 3s window
     * rather than deferring.
     *
     * Unlinked clickers get the ephemeral deep-link (the menu is meaningless
     * without a linked identity).
     */
    private function handleMenuRequest(string $gameId, ?User $user, string $guildId): JsonResponse
    {
        if (! $user instanceof User) {
            Log::info('discord_interaction.unlinked_deep_link', [
                'game_id' => $gameId,
                'guild_id' => $guildId,
            ]);

            return $this->unlinkedDeepLink($gameId);
        }

        $game = Game::with('participants')->find($gameId);

        if (! $game instanceof Game) {
            return $this->ephemeralMessage(
                "This game couldn't be found — it may have been removed."
            );
        }

        $context = $this->buildMenuContext($game, $user);
        $menu = $this->menuRenderer->render($game, $context);

        Log::info('discord_interaction.menu_shown', [
            'game_id' => $gameId,
            'user_id' => $user->id,
            'is_owner' => $context->isOwner,
            'current_status' => $context->currentStatus?->value,
        ]);

        return response()->json($menu->toResponse($context->appUrl, $gameId));
    }

    /**
     * Resolve the clicker's current roster state from the pre-loaded Game.
     */
    private function buildMenuContext(Game $game, User $user): DiscordRsvpMenuContext
    {
        $isOwner = (string) $game->owner_id === (string) $user->id;

        // The clicker's participant row (if any), filtering to ACTIVE statuses.
        // Removed/Rejected are treated as "not on the roster" — the menu offers Join.
        $participant = $game->participants->firstWhere('user_id', $user->id);

        return new DiscordRsvpMenuContext(
            isOwner: $isOwner,
            currentStatus: $contextStatus = $this->resolveActiveStatus($participant),
            waitlistPosition: $contextStatus === ParticipantStatus::Waitlisted
                ? $this->waitlistPosition($game, $user)
                : null,
            approvedCount: $game->participants->where('status', ParticipantStatus::Approved)->count(),
            maxPlayers: $game->max_players,
            appUrl: is_string(config('app.url')) ? config('app.url') : null,
        );
    }

    /**
     * The clicker's active participant status, or null if they are not on the
     * active roster (Removed/Rejected/no row → null → the menu offers Join).
     */
    private function resolveActiveStatus(?GameParticipant $participant): ?ParticipantStatus
    {
        if (! $participant) {
            return null;
        }

        $status = $participant->status;

        return in_array($status, [
            ParticipantStatus::Approved,
            ParticipantStatus::Waitlisted,
            ParticipantStatus::Benched,
            ParticipantStatus::Pending,
        ], true) ? $status : null;
    }

    /**
     * 1-based waitlist position for a waitlisted clicker (ordered by
     * waitlisted_at ascending). Null when not waitlisted or indeterminate.
     */
    private function waitlistPosition(Game $game, User $user): ?int
    {
        $waitlisted = $game->participants
            ->where('status', ParticipantStatus::Waitlisted)
            ->sortBy('waitlisted_at')
            ->values();

        foreach ($waitlisted as $i => $p) {
            if ($p->user_id === $user->id) {
                return $i + 1;
            }
        }

        return null;
    }

    // ── Deferred join/leave (the menu's action buttons) ──

    /**
     * Defer a join or leave action: unlinked → deep-link; linked → dispatch the
     * job + DEFERRED ack. The job resolves the interaction later via @original.
     *
     * @param  class-string  $jobClass  ProcessDiscordRsvp::class or ProcessDiscordLeave::class
     * @param  string  $logKey  'join_dispatched' or 'leave_dispatched'
     */
    private function handleDeferredAction(
        string $gameId,
        ?User $user,
        string $guildId,
        string $interactionToken,
        string $jobClass,
        string $logKey,
    ): JsonResponse {
        if (! $user instanceof User) {
            Log::info('discord_interaction.unlinked_deep_link', [
                'game_id' => $gameId,
                'guild_id' => $guildId,
            ]);

            return $this->unlinkedDeepLink($gameId);
        }

        $jobClass::dispatch(
            $gameId,
            (string) $user->id,
            $guildId,
            $interactionToken,
        );

        Log::info("discord_interaction.{$logKey}", [
            'game_id' => $gameId,
            'user_id' => $user->id,
            'guild_id' => $guildId,
        ]);

        // Discord requires HTTP 200 for ALL interaction responses (a 202
        // causes the @original webhook to never be created → PATCH 404s).
        return response()->json(['type' => self::TYPE_DEFERRED], 200);
    }

    // ── Unlinked deep-link ──────────────────────────────

    /**
     * Build the ephemeral deep-link response for an unlinked clicker: a LINK
     * button to the game page + value-framed copy. Ephemeral so it's private.
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
                        'type' => 1,
                        'components' => [
                            [
                                'type' => 2,
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

    private function gameDeepLinkUrl(string $gameId): string
    {
        $baseUrl = is_string(config('app.url')) ? rtrim(config('app.url'), '/') : '';

        return $baseUrl.'/games/'.$gameId;
    }

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

    private function handleUnknown(Request $request, int $type): JsonResponse
    {
        Log::info('discord_interaction.unhandled_type', [
            'type' => $type,
        ]);

        return response()->json(['type' => self::TYPE_DEFERRED], 200);
    }

    // ── Payload extraction ──────────────────────────────

    private function interactionType(Request $request): int
    {
        $type = $request->input('type');

        return is_int($type) ? $type : 0;
    }

    private function customId(Request $request): ?string
    {
        $customId = $request->input('data.custom_id');

        return is_string($customId) && $customId !== '' ? $customId : null;
    }

    /**
     * Parse a roundup custom_id (`roundup:{action}:{gameId}`) into its action
     * + game id, or null when malformed or the action is unknown.
     *
     * @return array{action: string, gameId: string}|null
     */
    private function parseCustomId(?string $customId): ?array
    {
        if ($customId === null) {
            return null;
        }

        if (! str_starts_with($customId, self::CUSTOM_ID_PREFIX)) {
            return null;
        }

        $rest = substr($customId, strlen(self::CUSTOM_ID_PREFIX));

        if (! str_contains($rest, ':')) {
            return null;
        }

        [$action, $gameId] = explode(':', $rest, 2);

        if (! in_array($action, [self::ACTION_MENU, self::ACTION_JOIN, self::ACTION_LEAVE], true)) {
            return null;
        }

        return $gameId === '' ? null : ['action' => $action, 'gameId' => $gameId];
    }

    private function memberSnowflake(Request $request): string
    {
        $memberUserId = $request->input('member.user.id');
        if (is_string($memberUserId) && $memberUserId !== '') {
            return $memberUserId;
        }

        $dmUserId = $request->input('user.id');

        return is_string($dmUserId) && $dmUserId !== '' ? $dmUserId : '';
    }

    private function guildId(Request $request): string
    {
        $guildId = $request->input('guild_id');

        return is_string($guildId) && $guildId !== '' ? $guildId : '';
    }

    private function interactionToken(Request $request): string
    {
        $token = $request->input('token');

        return is_string($token) && $token !== '' ? $token : '';
    }
}
