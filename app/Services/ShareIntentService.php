<?php

namespace App\Services;

use App\Dto\EntityMeta;
use App\Dto\JoinIntent;
use App\Dto\ShareIntentResult;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Exceptions\SignupCutoffReached;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Domain service for share-intent participant creation.
 *
 * Handles validating share tokens, re-checking entity state under lock, and
 * routing the participant write through the join intake
 * ({@see ParticipantLifecycle::createOrReactivate()}) so overflow routing,
 * approved_at stamping, the signup cutoff (D124), and the source-attributed
 * join log are enforced uniformly across every join path. Extracted from
 * ProcessShareIntent middleware so the logic is testable without HTTP.
 */
class ShareIntentService
{
    public function __construct(
        private readonly ParticipantLifecycle $participantLifecycle,
    ) {}

    /**
     * Process a share-token intent for a game or campaign.
     *
     * @param  array{entity_type: string, entity_id: string, share_token: string}  $payload
     */
    public function processShareIntent(array $payload, User $user): ShareIntentResult
    {
        $entityType = $payload['entity_type'];
        $entityId = $payload['entity_id'];
        $shareToken = $payload['share_token'];

        return match ($entityType) {
            'game' => $this->processGameShareIntent($entityId, $shareToken, $user),
            'campaign' => $this->processCampaignShareIntent($entityId, $shareToken, $user),
            default => $this->failResult("Unknown entity type: {$entityType}", (string) $user->id, $entityType, $entityId),
        };
    }

    /**
     * Process a short-link intent.
     *
     * Derives entity identity from the short link record — the cookie only
     * carries short_link_id, so there is no attacker-controlled entity data.
     */
    public function processShortLinkIntent(ShortLink $shortLink, User $user): ShareIntentResult
    {
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
                "Unsupported entity type: {$entityType}", (string) $user->id, $entityType, $entityId
            ),
        };
    }

    // ── Share Token Intent ──────────────────────────────────

    private function processGameShareIntent(string $entityId, string $shareToken, User $user): ShareIntentResult
    {
        $game = Game::find($entityId);

        if (! $game) {
            Log::warning('share_intent.entity_not_found', [
                'user_id' => $user->id, 'entity_type' => 'game', 'entity_id' => $entityId,
            ]);

            return new ShareIntentResult(false, null);
        }

        if (! $this->validateShareToken($game, $shareToken, 'game', $entityId, (string) $user->id)) {
            return new ShareIntentResult(false, null);
        }

        if ((string) $game->owner_id === (string) $user->id) {
            return new ShareIntentResult(true, 'games.show');
        }

        return $this->createParticipantForEntity(
            $game, $user, JoinSource::ShareLink, null
        );
    }

    private function processCampaignShareIntent(string $entityId, string $shareToken, User $user): ShareIntentResult
    {
        $campaign = Campaign::find($entityId);

        if (! $campaign) {
            Log::warning('share_intent.entity_not_found', [
                'user_id' => $user->id, 'entity_type' => 'campaign', 'entity_id' => $entityId,
            ]);

            return new ShareIntentResult(false, null);
        }

        if (! $this->validateShareToken($campaign, $shareToken, 'campaign', $entityId, (string) $user->id)) {
            return new ShareIntentResult(false, null);
        }

        if ((string) $campaign->owner_id === (string) $user->id) {
            return new ShareIntentResult(true, 'campaigns.show');
        }

        return $this->createParticipantForEntity(
            $campaign, $user, JoinSource::ShareLink, null
        );
    }

    // ── Short Link Intent ───────────────────────────────────

    /** @param Game|Campaign|null $entity */
    private function processShortLinkEntity(
        ?Model $entity,
        User $user,
        string $entityType,
        ShortLink $shortLink,
    ): ShareIntentResult {
        if (! in_array($entityType, ['game', 'campaign'], true)) {
            return $this->failShortLinkResult(
                "Unsupported entity type: {$entityType}",
                (string) $user->id,
                $entityType,
                (string) $shortLink->linkable_id,
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

        if ((string) $entity->owner_id === (string) $user->id) {
            return new ShareIntentResult(true, $route, entityId: is_string($k = $entity->getKey()) || is_int($k) ? (string) $k : null);
        }

        return $this->createParticipantForEntity(
            $entity, $user, JoinSource::ShortLink, $shortLink->id, true
        );
    }

    // ── Participant Creation ────────────────────────────────

    /**
     * Create a participant for the given entity with proper locking.
     *
     * Handles: existing-participant detection, entity-active re-check under
     * lock, concurrent-request protection via lockForUpdate, and unique
     * constraint fallback for races lost before the lock. The row write
     * itself routes through the join intake
     * ({@see ParticipantLifecycle::createOrReactivate()}): the intake owns
     * overflow routing ({@see OverflowRouter}), approved_at stamping, the
     * reactivation reset base, and the source-attributed participant.joined
     * log. Entity-type dispatch (participant class / foreign key / route)
     * derives from {@see EntityMeta::fromEntity()} — no string switches.
     *
     * The join is user-initiated, so the signup cutoff (D124) now applies on
     * this path: a refused join resolves to a graceful no-op that still lands
     * the user on the entity page (owner-visit parity), with no row written.
     *
     * @param  int|null  $shortLinkId  Set when arriving via short link
     * @param  bool  $clearCookieOnInactive  Whether to clear cookie when entity is inactive
     */
    private function createParticipantForEntity(
        Game|Campaign $entity,
        User $user,
        JoinSource $joinSource,
        ?int $shortLinkId,
        bool $clearCookieOnInactive = false,
    ): ShareIntentResult {
        $meta = EntityMeta::fromEntity($entity);
        $route = $meta->isCampaign() ? 'campaigns.show' : 'games.show';
        $key = $entity->getKey();
        $entityId = is_string($key) || is_int($key) ? (string) $key : null;

        // Pre-lock check: already a participant? Any existing row for the
        // user short-circuits — the intake is only reached for new joins.
        $existing = $meta->participantClass::where($meta->foreignKey, $key)
            ->whereBelongsTo($user)
            ->first();

        if ($existing) {
            Log::info('share_intent.already_participant', [
                'user_id' => $user->id, 'entity_type' => $meta->type,
                'entity_id' => $key, 'existing_status' => $existing->status->value ?? '',
            ]);

            return new ShareIntentResult(true, $route, entityId: $entityId);
        }

        // Wrap participant creation in a transaction with lockForUpdate —
        // the intake reads capacity post-lock inside this transaction.
        $created = false;

        try {
            DB::transaction(function () use ($entity, $user, $joinSource, $shortLinkId, $meta, &$created) {
                /** @var Game|Campaign $lockedEntity */
                $lockedEntity = $entity->lockForUpdate()->findOrFail($entity->getKey());

                // Check entity is still active
                $terminalStatuses = $lockedEntity instanceof Game
                    ? [GameStatus::Completed, GameStatus::Canceled]
                    : [CampaignStatus::Cancelled, CampaignStatus::Completed];

                $currentStatus = $lockedEntity->status;

                if (in_array($currentStatus, $terminalStatuses, true)) {
                    Log::warning('share_intent.entity_inactive', [
                        'user_id' => $user->id, 'entity_type' => $meta->type,
                        'entity_id' => $lockedEntity->getKey(), 'status' => $currentStatus->value,
                    ]);

                    return;
                }

                // Re-check under lock: a concurrent request may have created
                // the row between the pre-lock check and lock acquisition.
                $alreadyExists = $meta->participantClass::where($meta->foreignKey, $lockedEntity->getKey())
                    ->whereBelongsTo($user)
                    ->exists();

                if ($alreadyExists) {
                    $created = true;

                    return;
                }

                $this->participantLifecycle->createOrReactivate(
                    JoinIntent::join($lockedEntity, $user, $joinSource, $shortLinkId)
                );

                $created = true;
            });
        } catch (SignupCutoffReached) {
            // D124 cutoff refused the join (logged by the intake as
            // participant.join_refused) — no row was written. Graceful no-op:
            // land the user on the entity page like an owner visit so the
            // cookie is consumed rather than retried on every request.
            return new ShareIntentResult(true, $route, entityId: $entityId);
        } catch (QueryException $e) {
            Log::warning('share_intent.duplicate_participant', [
                'user_id' => $user->id, 'entity_type' => $meta->type,
                'entity_id' => $key, 'error' => $e->getMessage(),
            ]);

            return new ShareIntentResult(true, $route, entityId: $entityId);
        }

        if (! $created) {
            return new ShareIntentResult(false, null, shouldClearCookie: $clearCookieOnInactive);
        }

        return new ShareIntentResult(true, $route, entityId: $entityId);
    }

    // ── Helpers ─────────────────────────────────────────────

    /**
     * @param  Game|Campaign  $entity
     *                                 Validate that the entity's share_token matches the token from the cookie.
     */
    private function validateShareToken(Model $entity, string $token, string $entityType, string $entityId, string $userId): bool
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
     * Parse the share_intent cookie payload.
     *
     * Returns null if the payload is malformed or contains an unsupported entity type.
     *
     * @return array{entity_type: string, entity_id: string, share_token: string}|null
     */
    public function parsePayload(mixed $shareIntent): ?array
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
     *
     * @return array<string, mixed>
     */
    public function parseShortLinkPayload(mixed $cookieValue): ?array
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
}
