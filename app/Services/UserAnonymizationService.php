<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Jobs\DeletePostHogUserData;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Handles the full user anonymization flow for account deletion.
 *
 * Replaces destructive hard-delete with in-place PII anonymization.
 * Hard-deletes only Tier 1 private data (OAuth tokens, push subscriptions,
 * location tracking, etc.) while preserving all operational relationships
 * (reviews, participations, teams) intact without FK constraint changes.
 *
 * All operations are wrapped in a DB transaction for atomicity.
 * The user row is updated LAST (PII strip + anonymized_at) to maintain
 * referential integrity throughout the process.
 */
class UserAnonymizationService
{
    /**
     * Tier 1 tables hard-deleted during anonymization.
     *
     * These contain private/auth/tracking data that must not survive
     * account deletion. Each is keyed by table name for DB::table deletes.
     */
    private const TIER1_TABLES = [
        'linked_accounts',
        'push_subscriptions',
        'nearby_discovery_views',
        'user_game_system_preferences',
        'user_vibe_preferences',
        'user_app_visits',
        'local_subscriptions',
        'gm_social_links',
    ];

    /**
     * Anonymize a user in-place: cancel orphaned entities, hard-delete Tier 1 data, strip PII.
     *
     * @throws \Throwable on unrecoverable failure (transaction rolled back)
     */
    public function anonymize(User $user): void
    {
        $userId = $user->id;

        Log::info('User anonymization started', [
            'user_id' => $userId,
        ]);

        // Check analytics consent from the persisted user record.
        // This avoids depending on request/cookie context, which is
        // unavailable in artisan/queue contexts.
        $hadAnalyticsConsent = (bool) $user->analytics_consent;

        DB::transaction(function () use ($user, $userId, $hadAnalyticsConsent): void {
            // 1. Audit log entry BEFORE anonymization — non-PII identifiers only.
            //    Original email/name are NOT logged to comply with Art. 17 erasure.
            Log::info('User anonymization: audit snapshot', [
                'user_id' => $userId,
                'email_hash' => hash_hmac('sha256', $user->email, config('app.key')),
                'slug_hash' => hash_hmac('sha256', $user->slug ?? '', config('app.key')),
                'had_analytics_consent' => $hadAnalyticsConsent,
            ]);

            // 1b. Invalidate sessions BEFORE touching data so the user cannot
            //     issue concurrent authenticated requests during the transaction.
            DB::table('sessions')->where('user_id', $userId)->delete();

            // 1c. Acquire a pessimistic row lock to prevent concurrent writes
            //     (e.g., profile update) from restoring PII mid-transaction.
            $user = User::where('id', $userId)->lockForUpdate()->firstOrFail();

            // 1d. Cancel active games/campaigns where the user is sole host.
            //     This prevents orphaned active entities with a "Deleted User" host.
            $this->cancelSoleHostedEntities($userId);

            // 2. Hard-delete Tier 1 data
            foreach (self::TIER1_TABLES as $table) {
                $deleted = DB::table($table)->where('user_id', $userId)->delete();
                if ($deleted > 0) {
                    Log::debug("User anonymization: deleted {$deleted} rows from {$table}", [
                        'user_id' => $userId,
                    ]);
                }
            }

            // 3. Delete avatar/media files
            $this->deleteMedia($user);

            // 4. Strip PII on the user row LAST (preserves referential integrity)
            $uuid = Str::uuid()->toString();

            // Email is set to a non-existent @deleted.roundup.games address.
            // This domain has no MX record, so any accidental email delivery
            // will soft-fail at the MTA rather than bounce to a real mailbox.
            //
            // paddle_id is intentionally nulled — Paddle webhooks referencing
            // a deleted paddle_id must be handled gracefully by the webhook
            // controller (lookup returns null → log and skip).
            //
            // Note: 'location' is the JSON column (cast as array) that shadows
            // the location() BelongsTo relationship. Both location (JSON PII)
            // and location_id (FK) must be nulled to fully strip geographic data.
            $user->forceFill([
                'name' => 'Deleted User',
                'email' => "deleted-{$uuid}@deleted.roundup.games",
                'password' => Str::random(64),
                'phone' => null,
                'gender' => null,
                'pronouns' => null,
                'avatar_url' => null,
                'bio' => null,
                'location' => null,
                'location_id' => null,
                'paddle_id' => null,
                'slug' => "deleted-{$uuid}",
                'email_verified_at' => null,
                'password_set_at' => null,
                'privacy_settings' => null,
                'notification_settings' => null,
                'reliability_score' => null,
                'reliability_computed_at' => null,
                'profile_complete' => false,
                'privacy_policy_accepted_at' => null,
                'terms_accepted_at' => null,
                'remember_token' => null,
                'anonymized_at' => now(),
                'analytics_consent' => false,
            ])->saveQuietly();
        });

        // 6. Queue PostHog data deletion (outside transaction — API call)
        if ($hadAnalyticsConsent) {
            DeletePostHogUserData::dispatch($userId);
        }

        // 7. Invalidate discovery caches so the anonymized user doesn't
        //    appear in search, directories, or "people near you" with
        //    stale pre-anonymization data.
        try {
            app(DashboardCacheService::class)->invalidateForUser($userId);
        } catch (\Throwable $e) {
            Log::warning('User anonymization: discovery cache invalidation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('User anonymization completed', [
            'user_id' => $userId,
            'anonymized_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Cancel active games and campaigns where the user is the sole owner.
     *
     * Uses Eloquent models (not raw DB queries) so that the existing
     * notification flows fire for each cancellation. Participants are
     * notified that the entity was cancelled, and attendance offences
     * are recorded for games.
     *
     * This is safe to call inside the anonymization transaction — the
     * notification dispatch is wrapped in try/catch so failures don't
     * block the transaction. Notifications use queued delivery, so the
     * actual send happens after commit.
     */
    private function cancelSoleHostedEntities(string $userId): void
    {
        // Cancel active games where this user is the owner
        $games = Game::where('owner_id', $userId)
            ->where('status', GameStatus::Scheduled)
            ->get();

        foreach ($games as $game) {
            $game->update(['status' => GameStatus::Canceled]);

            Log::info('User anonymization: cancelled sole-hosted game', [
                'user_id' => $userId,
                'game_id' => $game->id,
            ]);

            // Record attendance offence (for reliability scoring)
            try {
                app(AttendanceService::class)->recordHostCancellationOffence($game);
            } catch (\Throwable $e) {
                Log::warning('User anonymization: host cancellation offence failed', [
                    'game_id' => $game->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Notify approved participants (excluding the departing owner)
            try {
                $participants = $game->participants()
                    ->where('status', 'approved')
                    ->where('user_id', '!=', $userId)
                    ->with('user')
                    ->get();

                foreach ($participants as $participant) {
                    app(NotificationService::class)->send(
                        $participant->user,
                        new \App\Notifications\EntityCancelled($game),
                        NotificationCategory::GameCancelled,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('User anonymization: game cancellation notifications failed', [
                    'game_id' => $game->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cancel active campaigns where this user is the owner
        $campaigns = Campaign::where('owner_id', $userId)
            ->where('status', CampaignStatus::Active)
            ->get();

        foreach ($campaigns as $campaign) {
            $campaign->update(['status' => CampaignStatus::Cancelled]);

            Log::info('User anonymization: cancelled sole-hosted campaign', [
                'user_id' => $userId,
                'campaign_id' => $campaign->id,
            ]);

            // Notify approved participants (excluding the departing owner)
            try {
                $participants = $campaign->participants()
                    ->where('status', 'approved')
                    ->where('user_id', '!=', $userId)
                    ->with('user')
                    ->get();

                foreach ($participants as $participant) {
                    app(NotificationService::class)->send(
                        $participant->user,
                        new \App\Notifications\EntityCancelled($campaign),
                        NotificationCategory::CampaignCancelled,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('User anonymization: campaign cancellation notifications failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete all media files associated with the user (avatars, etc.).
     */
    private function deleteMedia(User $user): void
    {
        try {
            $mediaCount = $user->media->count();

            if ($mediaCount > 0) {
                // Delete each media file properly (removes files from disk + DB records)
                $user->media->each(fn (Media $media) => $media->delete());

                Log::debug('User anonymization: deleted media files', [
                    'user_id' => $user->id,
                    'media_count' => $mediaCount,
                ]);
            }
        } catch (\Throwable $e) {
            // Media deletion failure should not block anonymization
            Log::warning('User anonymization: media deletion failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
