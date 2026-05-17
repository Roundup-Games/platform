<?php

namespace App\Http\Middleware;

use App\Dto\ShareIntentResult;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\ShortLink;
use App\Services\ShortLinkService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ProcessShareIntent middleware.
 *
 * Intercepts encrypted cookies after auth transitions (registration, email
 * verification, login, onboarding completion) to auto-create participants.
 *
 * Two cookie flows:
 *   1. short_link_intent — carries short_link_id only; entity derived from link
 *   2. share_intent — carries entity_type, entity_id, share_token
 *
 * If the user's profile is incomplete, defers processing (cookie persists).
 */
class ProcessShareIntent
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only process for authenticated users on page-level GET requests
        if (! $user || ! $this->shouldProcess($request)) {
            return $next($request);
        }

        // ── Short link intent processing ───────────────────────────────
        $shortLinkIntent = $request->cookie('short_link_intent');

        if ($shortLinkIntent && $user->profile_complete) {
            $result = $this->processShortLinkIntent($shortLinkIntent, $user);

            if ($result->shouldRedirect && $result->redirectRoute) {
                return redirect()->route($result->redirectRoute, [
                    'id' => $result->entityId,
                ])->withCookie(cookie()->forget('short_link_intent'));
            }

            // Invalid or already processed — clear cookie
            if ($result->shouldClearCookie) {
                $response = $next($request);
                $response->withCookie(cookie()->forget('short_link_intent'));

                return $response;
            }
        } elseif ($shortLinkIntent && ! $user->profile_complete) {
            Log::debug('short_link_intent.deferred_profile_incomplete', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);
        }

        // ── Share token intent processing (existing flow) ──────────────
        $shareIntent = $request->cookie('share_intent');

        if (! $shareIntent) {
            return $next($request);
        }

        if (! $user->profile_complete) {
            Log::debug('share_intent.deferred_profile_incomplete', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        $payload = $this->parsePayload($shareIntent);

        if ($payload === null) {
            Log::warning('share_intent.invalid_payload', [
                'user_id' => $user->id,
            ]);

            return $this->clearCookie($next($request));
        }

        $result = $this->processShareIntent($payload, $user);

        if ($result->shouldRedirect && $result->redirectRoute) {
            Log::info('share_intent.redirecting', [
                'user_id' => $user->id,
                'entity_type' => $payload['entity_type'],
                'entity_id' => $payload['entity_id'],
                'route' => $result->redirectRoute,
            ]);

            return redirect()->route($result->redirectRoute, [
                'id' => $payload['entity_id'],
            ])->withCookie(cookie()->forget('share_intent'));
        }

        return $this->clearCookie($next($request));
    }

    // ── Share Token Intent ──────────────────────────────────

    /**
     * Process the share intent: validate entity, check capacity, create participant.
     */
    private function processShareIntent(array $payload, $user): ShareIntentResult
    {
        $entityType = $payload['entity_type'];
        $entityId = $payload['entity_id'];
        $shareToken = $payload['share_token'];

        return match ($entityType) {
            'game' => $this->processGameShareIntent($entityId, $shareToken, $user),
            'campaign' => $this->processCampaignShareIntent($entityId, $shareToken, $user),
            default => $this->failResult("Unknown entity type: {$entityType}", $user->id, $entityType, $entityId),
        };
    }

    private function processGameShareIntent(string $entityId, string $shareToken, $user): ShareIntentResult
    {
        $game = Game::find($entityId);

        if (! $game) {
            Log::warning('share_intent.entity_not_found', [
                'user_id' => $user->id, 'entity_type' => 'game', 'entity_id' => $entityId,
            ]);

            return new ShareIntentResult(false, null);
        }

        if (! $this->validateShareToken($game, $shareToken, 'game', $entityId, $user->id)) {
            return new ShareIntentResult(false, null);
        }

        if ($game->owner_id === $user->id) {
            return new ShareIntentResult(true, 'games.show');
        }

        return $this->createParticipantForEntity(
            $game, $user, 'game', JoinSource::ShareLink, null
        );
    }

    private function processCampaignShareIntent(string $entityId, string $shareToken, $user): ShareIntentResult
    {
        $campaign = Campaign::find($entityId);

        if (! $campaign) {
            Log::warning('share_intent.entity_not_found', [
                'user_id' => $user->id, 'entity_type' => 'campaign', 'entity_id' => $entityId,
            ]);

            return new ShareIntentResult(false, null);
        }

        if (! $this->validateShareToken($campaign, $shareToken, 'campaign', $entityId, $user->id)) {
            return new ShareIntentResult(false, null);
        }

        if ($campaign->owner_id === $user->id) {
            return new ShareIntentResult(true, 'campaigns.show');
        }

        return $this->createParticipantForEntity(
            $campaign, $user, 'campaign', JoinSource::ShareLink, null
        );
    }

    // ── Short Link Intent ───────────────────────────────────

    /**
     * Process the short_link_intent cookie.
     *
     * Derives entity identity entirely from the short link record — the cookie
     * only carries short_link_id, so there is no attacker-controlled entity data.
     */
    private function processShortLinkIntent(mixed $cookieValue, $user): ShareIntentResult
    {
        $payload = $this->parseShortLinkPayload($cookieValue);

        if ($payload === null) {
            Log::warning('short_link_intent.invalid_payload', ['user_id' => $user->id]);

            return new ShareIntentResult(false, null, shouldClearCookie: true);
        }

        $shortLink = app(ShortLinkService::class)->resolveLinkById((int) $payload['short_link_id']);

        if ($shortLink === null) {
            Log::warning('short_link_intent.link_not_found', [
                'user_id' => $user->id, 'short_link_id' => $payload['short_link_id'],
            ]);

            return new ShareIntentResult(false, null, shouldClearCookie: true);
        }

        // Derive entity identity from the short link — not from the cookie.
        $entityType = strtolower(class_basename($shortLink->linkable_type));
        $entityId = (string) $shortLink->linkable_id;

        return match ($entityType) {
            'game' => $this->processShortLinkEntity(
                Game::find($entityId), $user, 'game', $shortLink
            ),
            'campaign' => $this->processShortLinkEntity(
                Campaign::find($entityId), $user, 'campaign', $shortLink
            ),
            default => $this->failShortLinkResult(
                "Unsupported entity type: {$entityType}", $user->id, $entityType, $entityId
            ),
        };
    }

    /**
     * Validate and create participant for a short link entity arrival.
     */
    private function processShortLinkEntity(
        $entity,
        $user,
        string $entityType,
        ShortLink $shortLink,
    ): ShareIntentResult {
        // Only Game and Campaign are supported — both use integer PKs
        // matched by the {id} route parameter. If additional entity types
        // with string PKs (e.g. Team by slug) are added, the redirect
        // route resolution must be updated to handle the correct parameter.
        if (! in_array($entityType, ['game', 'campaign'], true)) {
            return $this->failShortLinkResult(
                "Unsupported entity type: {$entityType}",
                $user->id,
                $entityType,
                $shortLink->linkable_id,
            );
        }

        $route = $entityType === 'game' ? 'games.show' : 'campaigns.show';

        if (! $entity) {
            Log::warning('short_link_intent.entity_not_found', [
                'user_id' => $user->id, 'entity_type' => $entityType,
                'entity_id' => $shortLink->linkable_id,
            ]);

            return new ShareIntentResult(false, null, shouldClearCookie: true);
        }

        if ($entity->owner_id === $user->id) {
            return new ShareIntentResult(true, $route, entityId: $entity->getKey());
        }

        return $this->createParticipantForEntity(
            $entity, $user, $entityType, JoinSource::ShortLink, $shortLink->id, true
        );
    }

    // ── Shared Participant Creation ─────────────────────────

    /**
     * Create a participant for the given entity with proper locking.
     *
     * Handles: existing-participant detection, capacity-based status,
     * concurrent-request protection via lockForUpdate, and unique constraint
     * fallback for races lost before the lock.
     *
     * @param  Game|Campaign  $entity
     * @param  string  $entityType  'game' or 'campaign'
     * @param  int|null  $shortLinkId  Set when arriving via short link
     * @param  bool  $clearCookieOnInactive  Whether to clear cookie when entity is inactive
     */
    private function createParticipantForEntity(
        $entity,
        $user,
        string $entityType,
        JoinSource $joinSource,
        ?int $shortLinkId,
        bool $clearCookieOnInactive = false,
    ): ShareIntentResult {
        $route = $entityType === 'game' ? 'games.show' : 'campaigns.show';
        $fkColumn = $entityType === 'game' ? 'game_id' : 'campaign_id';
        $modelClass = $entityType === 'game' ? GameParticipant::class : CampaignParticipant::class;

        // Pre-lock check: already a participant?
        $existing = $modelClass::where($fkColumn, $entity->getKey())
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            Log::info('share_intent.already_participant', [
                'user_id' => $user->id, 'entity_type' => $entityType,
                'entity_id' => $entity->getKey(), 'existing_status' => $existing->status->value,
            ]);

            return new ShareIntentResult(true, $route, entityId: $entity->getKey());
        }

        // Wrap participant creation in a transaction with lockForUpdate
        $status = null;

        try {
            DB::transaction(function () use ($entity, $user, $entityType, $joinSource, $shortLinkId, $fkColumn, $modelClass, &$status) {
                $lockedEntity = $entity->lockForUpdate()->find($entity->getKey());

                if (! $lockedEntity) {
                    Log::error('share_intent.entity_not_found_under_lock', [
                        'user_id' => $user->id, 'entity_type' => $entityType,
                        'entity_id' => $entity->getKey(),
                    ]);

                    return;
                }

                // Check entity is still active
                $terminalStatuses = $entityType === 'game'
                    ? [GameStatus::Completed, GameStatus::Canceled]
                    : [CampaignStatus::Cancelled, CampaignStatus::Completed];

                $currentStatus = $lockedEntity->status;

                if (in_array($currentStatus, $terminalStatuses, true)) {
                    Log::warning('share_intent.entity_inactive', [
                        'user_id' => $user->id, 'entity_type' => $entityType,
                        'entity_id' => $lockedEntity->getKey(), 'status' => $currentStatus->value,
                    ]);

                    return;
                }

                // Re-check under lock
                $alreadyExists = $modelClass::where($fkColumn, $lockedEntity->getKey())
                    ->where('user_id', $user->id)
                    ->exists();

                if ($alreadyExists) {
                    $status = ParticipantStatus::Approved;

                    return;
                }

                $status = $this->determineStatus($lockedEntity, $entityType);

                $participantData = [
                    $fkColumn => $lockedEntity->getKey(),
                    'user_id' => $user->id,
                    'role' => 'player',
                    'status' => $status,
                    'join_source' => $joinSource,
                ];

                if ($shortLinkId !== null) {
                    $participantData['short_link_id'] = $shortLinkId;
                }

                if ($status === ParticipantStatus::Waitlisted) {
                    $participantData['waitlisted_at'] = now();
                } elseif ($status === ParticipantStatus::Benched) {
                    $participantData['benched_at'] = now();
                }

                $modelClass::create($participantData);

                Log::info('share_intent.participant_created', [
                    'user_id' => $user->id, 'entity_type' => $entityType,
                    'entity_id' => $lockedEntity->getKey(), 'status' => $status->value,
                    'join_source' => $joinSource->value, 'short_link_id' => $shortLinkId,
                ]);
            });
        } catch (QueryException $e) {
            Log::warning('share_intent.duplicate_participant', [
                'user_id' => $user->id, 'entity_type' => $entityType,
                'entity_id' => $entity->getKey(), 'error' => $e->getMessage(),
            ]);

            return new ShareIntentResult(true, $route, entityId: $entity->getKey());
        }

        if ($status === null) {
            return new ShareIntentResult(false, null, shouldClearCookie: $clearCookieOnInactive);
        }

        return new ShareIntentResult(true, $route, entityId: $entity->getKey());
    }

    // ── Helpers ─────────────────────────────────────────────

    /**
     * Validate that the entity's share_token matches the token from the cookie.
     */
    private function validateShareToken($entity, string $token, string $entityType, string $entityId, string $userId): bool
    {
        if ($entity->share_token === null || $entity->share_token !== $token) {
            Log::warning('share_intent.token_mismatch', [
                'user_id' => $userId, 'entity_type' => $entityType, 'entity_id' => $entityId,
            ]);

            return false;
        }

        if ($entity->share_token_expires_at !== null && $entity->share_token_expires_at->isPast()) {
            Log::warning('share_intent.token_expired', [
                'user_id' => $userId, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'expires_at' => $entity->share_token_expires_at->toIso8601String(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Determine participant status based on entity capacity.
     *
     * Games → benched when full + campaign session, waitlisted when full + standalone.
     * Campaigns → benched when full.
     */
    private function determineStatus($entity, string $entityType): ParticipantStatus
    {
        $approvedCount = $entity->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($entity->max_players && $approvedCount >= $entity->max_players) {
            if ($entityType === 'campaign') {
                return ParticipantStatus::Benched;
            }

            return $entity->campaign_id !== null
                ? ParticipantStatus::Benched
                : ParticipantStatus::Waitlisted;
        }

        return ParticipantStatus::Approved;
    }

    /**
     * Parse the share_intent cookie payload.
     */
    private function parsePayload(mixed $shareIntent): ?array
    {
        if (is_array($shareIntent)) {
            if (! isset($shareIntent['entity_type'], $shareIntent['entity_id'], $shareIntent['share_token'])) {
                return null;
            }

            if (! in_array($shareIntent['entity_type'], ['game', 'campaign'], true)) {
                return null;
            }

            return $shareIntent;
        }

        if (! is_string($shareIntent)) {
            return null;
        }

        $data = json_decode($shareIntent, true);

        if (! is_array($data) || ! isset($data['entity_type'], $data['entity_id'], $data['share_token'])) {
            return null;
        }

        return in_array($data['entity_type'], ['game', 'campaign'], true) ? $data : null;
    }

    /**
     * Parse the short_link_intent cookie payload.
     *
     * Only short_link_id is expected — entity identity is derived server-side.
     */
    private function parseShortLinkPayload(mixed $cookieValue): ?array
    {
        $data = is_array($cookieValue) ? $cookieValue : null;

        if ($data === null && is_string($cookieValue)) {
            $decoded = json_decode($cookieValue, true);
            $data = is_array($decoded) ? $decoded : null;
        }

        if ($data === null || ! isset($data['short_link_id'])) {
            return null;
        }

        return ['short_link_id' => $data['short_link_id']];
    }

    private function clearCookie(Response $response): Response
    {
        $response->withCookie(cookie()->forget('share_intent'));

        return $response;
    }

    private function failResult(string $reason, string $userId, string $entityType, string $entityId): ShareIntentResult
    {
        Log::warning('share_intent.failed', [
            'user_id' => $userId, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'reason' => $reason,
        ]);

        return new ShareIntentResult(false, null);
    }

    private function failShortLinkResult(string $reason, string $userId, string $entityType, string $entityId): ShareIntentResult
    {
        Log::warning('short_link_intent.failed', [
            'user_id' => $userId, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'reason' => $reason,
        ]);

        return new ShareIntentResult(false, null, shouldClearCookie: true);
    }

    /**
     * Determine if this request should be checked for share intent processing.
     */
    private function shouldProcess(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->is('api/*')
            && ! $request->header('X-Livewire');
    }
}
