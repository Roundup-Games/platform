<?php

namespace App\Services;

use App\Dto\ParticipantResult;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single, lock-guarded share-link join pipeline for Game and Campaign.
 *
 * This is the app's most race-sensitive mutation: the entity row is locked
 * FOR UPDATE, the short link is revalidated under the lock (catching
 * mid-session revocation, falling back to a still-valid share token),
 * capacity is re-checked under the lock, and full entities route to the
 * overflow primitive (waitlist/bench) — all inside one transaction. This
 * service replaced three near-verbatim copies (GameDetail::joinViaShareLink,
 * GameDetail::joinViaDiscord, CampaignDetail::joinViaShareLink) whose silent
 * divergence was the highest-blast-radius debt in the codebase: a race fix
 * applied to one copy used to miss the others.
 *
 * Deliberate per-entity differences (schema, not drift):
 * - Games create through {@see ParticipantLifecycle::createOrReactivate()} so
 *   a departed row reactivates with its audit fields reset; campaigns have no
 *   departure lifecycle and create the row directly.
 * - Games stamp approved_at on direct joins so the LIFO capacity-demotion
 *   ordering is correct; CampaignParticipant has no approved_at column.
 *
 * Components keep the pre-guards (canJoinViaShareLink / signup cutoff / rate
 * limiting), the post-transaction surface effects (cookie clears, Discord
 * intent consumption, computed-prop refresh) and flash rendering.
 */
class ShareLinkJoinService
{
    public function __construct(
        private readonly ParticipantService $participantService,
        private readonly OverflowRouter $overflowRouter,
        private readonly ParticipantLifecycle $participantLifecycle,
    ) {}

    /**
     * Join the viewer onto the entity via a share link / short link / Discord
     * intent, under the row lock.
     *
     * @param  JoinSource  $joinSource  ShortLink, ShareLink, or Discord
     * @param  int|null  $shortLinkId  Validated short link id (null → token join)
     * @param  string|null  $shareToken  Captured share-token query param, used
     *                                   only as the mid-session revocation fallback
     * @return ParticipantResult|null Overflow outcome when the entity was full
     *                                (caller renders its flash message); null on direct join
     *
     * @throws \RuntimeException When the entity vanished mid-transaction, or the
     *                           short link was revoked with no valid token fallback
     */
    public function join(Game|Campaign $entity, User $viewer, JoinSource $joinSource, ?int $shortLinkId = null, ?string $shareToken = null): ?ParticipantResult
    {
        $overflowFlash = null;

        DB::transaction(function () use ($entity, $viewer, $joinSource, $shortLinkId, $shareToken, &$overflowFlash): void {
            /** @var Game|Campaign|null $locked */
            $locked = $entity->newQuery()->lockForUpdate()->find($entity->getKey());

            if ($locked === null) {
                throw new \RuntimeException(class_basename($entity).' not found during join transaction.');
            }

            // Revalidate the short link under the lock to catch mid-session
            // revocation. If revoked, fall back to the share token if one is
            // still valid for this entity.
            if ($shortLinkId !== null) {
                $freshLink = ShortLink::where('id', $shortLinkId)
                    ->whereNull('deleted_at')
                    ->first();

                if ($freshLink === null || $freshLink->isExpired()) {
                    if ($shareToken !== null && $locked->hasValidShareToken($shareToken)) {
                        $shortLinkId = null;
                        $joinSource = JoinSource::ShareLink;
                    } else {
                        throw new \RuntimeException('Short link revoked or expired during join.');
                    }
                }
            }

            $isFull = $this->participantService->isAtCapacity($locked);

            $baseData = array_filter([
                $locked->getForeignKey() => $locked->getKey(),
                'user_id' => $viewer->id,
                'role' => ParticipantRole::Player->value,
                'join_source' => $joinSource->value,
                'short_link_id' => $shortLinkId,
            ], fn ($value): bool => $value !== null);

            $via = $joinSource === JoinSource::Discord ? 'Discord intent' : 'share link';
            $label = $locked instanceof Game ? 'game' : 'campaign';

            if ($isFull) {
                $overflow = $this->overflowRouter->resolve($locked);
                $baseData['status'] = $overflow->statusValue();
                $baseData[$overflow->timestampColumn] = now();

                $this->createParticipant($locked, $baseData);

                $overflowFlash = $this->overflowRouter->flashResult($locked);

                Log::info('Player '.$overflow->statusValue().' via '.$via.' ('.$label.' full)', [
                    $label.'_id' => $locked->getKey(),
                    'user_id' => $viewer->id,
                    'join_source' => $joinSource->value,
                    'short_link_id' => $shortLinkId,
                ]);
            } else {
                $baseData['status'] = ParticipantStatus::Approved->value;

                // Stamp approved_at so LIFO capacity-demotion ordering is
                // correct for direct joins — without this, the demote query's
                // `approved_at IS NULL ASC` ordering would shield these players
                // from demotion (MEM: stamp every Approved transition). Mirrors
                // WaitlistService::confirmPromotion. Games only: the column
                // exists on GameParticipant, not CampaignParticipant.
                if ($locked instanceof Game) {
                    $baseData['approved_at'] = now();
                }

                $this->createParticipant($locked, $baseData);

                Log::info('Player joined '.($locked instanceof Campaign ? 'campaign ' : '').'via '.$via, [
                    $label.'_id' => $locked->getKey(),
                    'user_id' => $viewer->id,
                    'join_source' => $joinSource->value,
                    'short_link_id' => $shortLinkId,
                ]);
            }
        });

        return $overflowFlash;
    }

    /**
     * Create the participant row through the entity's creation primitive.
     *
     * Games go through the lifecycle's reactivation-aware create (a departed
     * row rejoins with its audit fields reset); campaigns create directly.
     *
     * @param  array<string, mixed>  $baseData
     */
    private function createParticipant(Game|Campaign $locked, array $baseData): void
    {
        if ($locked instanceof Game) {
            $this->participantLifecycle->createOrReactivate($baseData);

            return;
        }

        CampaignParticipant::create($baseData);
    }
}
