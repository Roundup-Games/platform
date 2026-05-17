<?php

namespace App\Services;

use App\Jobs\DeletePostHogUserData;
use App\Models\User;
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

    public function __construct(
        private readonly PostHogConsentChecker $consentChecker,
    ) {}

    /**
     * Anonymize a user in-place: hard-delete Tier 1 data, strip PII.
     *
     * @throws \Throwable on unrecoverable failure (transaction rolled back)
     */
    public function anonymize(User $user): void
    {
        $userId = $user->id;
        $userEmail = $user->email;

        Log::info('User anonymization started', [
            'user_id' => $userId,
            'user_email' => $userEmail,
        ]);

        // Capture consent status before anonymization strips everything
        $hadAnalyticsConsent = $this->consentChecker->hasAnalyticsConsent();

        DB::transaction(function () use ($user, $userId, $hadAnalyticsConsent): void {
            // 1. Audit log entry BEFORE anonymization (user still has original PII)
            Log::info('User anonymization: audit snapshot', [
                'user_id' => $userId,
                'original_email' => $user->email,
                'original_name' => $user->name,
                'original_slug' => $user->slug,
                'had_analytics_consent' => $hadAnalyticsConsent,
            ]);

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

            // 4. Delete Laravel sessions for this user
            DB::table('sessions')->where('user_id', $userId)->delete();

            // 5. Strip PII on the user row LAST (preserves referential integrity)
            $uuid = Str::uuid()->toString();
            $user->forceFill([
                'name' => 'Deleted User',
                'email' => "deleted-{$uuid}@anonymous",
                'password' => Str::random(64),
                'phone' => null,
                'gender' => null,
                'pronouns' => null,
                'avatar_url' => null,
                'bio' => null,
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
            ])->saveQuietly();
        });

        // 6. Queue PostHog data deletion (outside transaction — API call)
        if ($hadAnalyticsConsent) {
            DeletePostHogUserData::dispatch($userId);
        }

        Log::info('User anonymization completed', [
            'user_id' => $userId,
            'anonymized_at' => now()->toIso8601String(),
        ]);
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
