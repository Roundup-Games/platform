<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GmSocialLink;
use App\Models\LinkedAccount;
use App\Models\PushSubscription;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class GenerateUserDataExport extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'export:user-data {user : User UUID}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a GDPR-compliant user data export ZIP bundle';

    /**
     * Schema version for the export manifest.
     */
    protected const SCHEMA_VERSION = '1.0.0';

    /**
     * Profile attributes safe for data export.
     *
     * Whitelist approach: only explicitly listed fields are exported.
     * When new columns are added to the User model, they must be
     * reviewed before adding to this list — never export by default.
     */
    protected const PROFILE_EXPORT_ATTRIBUTES = [
        'id', 'name', 'email', 'slug', 'bio', 'pronouns', 'gender',
        'preferred_language', 'profile_complete', 'location_id',
        'avatar_url', 'reliability_score', 'reliability_computed_at',
        'privacy_settings', 'notification_settings',
        'gender_consent', 'analytics_consent',
        'privacy_policy_accepted_at', 'terms_accepted_at',
        'email_verified_at', 'password_set_at',
        'created_at', 'updated_at',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userUuid = $this->argument('user');

        /** @var User|null $user */
        $user = User::where('id', $userUuid)->first();

        if (! $user) {
            $this->error("User with UUID [{$userUuid}] not found.");

            return self::FAILURE;
        }

        $this->info("Generating data export for user [{$user->name}] ({$user->id})");

        $tempDir = $this->createTempDirectory((string) $user->id);

        try {
            $fileChecksums = [];

            // 1. Profile
            $fileChecksums['profile.json'] = $this->writeJson(
                $tempDir,
                'profile.json',
                $this->gatherProfile($user),
            );

            // 2. Linked accounts
            $fileChecksums['linked-accounts.json'] = $this->writeJson(
                $tempDir,
                'linked-accounts.json',
                $this->gatherLinkedAccounts($user),
            );

            // 3. Games
            $fileChecksums['games.json'] = $this->writeJson(
                $tempDir,
                'games.json',
                $this->gatherGames($user),
            );

            // 4. Campaigns
            $fileChecksums['campaigns.json'] = $this->writeJson(
                $tempDir,
                'campaigns.json',
                $this->gatherCampaigns($user),
            );

            // 5. Events
            $fileChecksums['events.json'] = $this->writeJson(
                $tempDir,
                'events.json',
                $this->gatherEvents($user),
            );

            // 6. Reviews
            $fileChecksums['reviews.json'] = $this->writeJson(
                $tempDir,
                'reviews.json',
                $this->gatherReviews($user),
            );

            // 7. Teams
            $fileChecksums['teams.json'] = $this->writeJson(
                $tempDir,
                'teams.json',
                $this->gatherTeams($user),
            );

            // 8. Activity log
            $fileChecksums['activity-log.json'] = $this->writeJson(
                $tempDir,
                'activity-log.json',
                $this->gatherActivityLog($user),
            );

            // 9. Push subscriptions
            $fileChecksums['push-subscriptions.json'] = $this->writeJson(
                $tempDir,
                'push-subscriptions.json',
                $this->gatherPushSubscriptions($user),
            );

            // 10. Social links
            $fileChecksums['social-links.json'] = $this->writeJson(
                $tempDir,
                'social-links.json',
                $this->gatherSocialLinks($user),
            );

            // 11. Media files
            $mediaChecksums = $this->copyMediaFiles($user, $tempDir);
            $fileChecksums = array_merge($fileChecksums, $mediaChecksums);

            // 12. Manifest (includes all checksums)
            $fileChecksums['manifest.json'] = $this->writeJson(
                $tempDir,
                'manifest.json',
                $this->buildManifest($user, $fileChecksums),
            );

            // 13. Create ZIP
            $zipPath = $this->createZipArchive($tempDir, $user);

            // 14. Store ZIP on private disk
            $storedPath = $this->storeZip($zipPath, $user);
            $fileSize = Storage::disk('local')->size($storedPath);

            $this->info("Export stored at: {$storedPath}");
            $this->info('File size: '.format_bytes($fileSize));

            Log::info('User data export generated', [
                'user_id' => $user->id,
                'file_path' => $storedPath,
                'file_size' => $fileSize,
                'schema_version' => self::SCHEMA_VERSION,
            ]);

            // Output stored path for programmatic consumption
            $this->line($storedPath);

            return self::SUCCESS;
        } finally {
            // Clean up temp directory
            File::deleteDirectory($tempDir);
        }
    }

    // ── Data Gathering ────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function gatherProfile(User $user): array
    {
        $attributes = $user->attributesToArray();

        // Whitelist: only export known-safe fields.
        $export = [];
        foreach (self::PROFILE_EXPORT_ATTRIBUTES as $key) {
            if (array_key_exists($key, $attributes)) {
                $export[$key] = $attributes[$key];
            }
        }

        return $export;
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherLinkedAccounts(User $user): array
    {
        return $user->linkedAccounts()
            ->get()
            ->map(fn (LinkedAccount $account) => [
                'id' => $account->id,
                'provider' => $account->provider,
                'provider_user_id' => $account->provider_user_id,
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
                // provider_meta is intentionally excluded — it may contain OAuth
                // credentials (access tokens, refresh tokens) from provider flows.
                // Only export provider name and provider-side user ID.
                'created_at' => $account->created_at?->toIso8601String(),
                'updated_at' => $account->updated_at?->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherGames(User $user): array
    {
        // Whitelist game fields to avoid leaking other users' PII
        $ownedGames = $user->ownedGames()->get();
        /** @var Collection<int, Game> $ownedGames */
        $owned = $ownedGames->map(fn (Game $game) => [
            'id' => $game->id,
            'name' => $game->name,
            'status' => $game->status?->value,
            'date_time' => $game->date_time?->toIso8601String(),
            'max_players' => $game->max_players,
            'game_system_id' => $game->game_system_id,
            'created_at' => $game->created_at?->toIso8601String(),
        ]);

        // Build participation query with whitelisted fields
        $participated = Game::query()
            ->join('game_participants', 'games.id', '=', 'game_participants.game_id')
            ->where('game_participants.user_id', $user->id)
            ->select('games.id', 'games.name', 'games.status', 'games.date_time',
                'game_participants.role as pivot_role', 'game_participants.status as pivot_status')
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'date_time' => $game->date_time?->toIso8601String(),
                'pivot' => [
                    'role' => $game->pivot_role,
                    'status' => $game->pivot_status,
                ],
            ]);

        return [
            'owned' => $owned->toArray(),
            'participated' => $participated->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherCampaigns(User $user): array
    {
        // Whitelist campaign fields to avoid leaking other users' PII
        $owned = $user->ownedCampaigns()->get()->map(fn (Campaign $campaign) => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status?->value,
            'game_system_id' => $campaign->game_system_id,
            'created_at' => $campaign->created_at?->toIso8601String(),
        ]);

        // Build participation query with whitelisted fields
        $participated = Campaign::query()
            ->join('campaign_participants', 'campaigns.id', '=', 'campaign_participants.campaign_id')
            ->where('campaign_participants.user_id', $user->id)
            ->select('campaigns.id', 'campaigns.name', 'campaigns.status',
                'campaign_participants.role as pivot_role', 'campaign_participants.status as pivot_status')
            ->get()
            ->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'pivot' => [
                    'role' => $campaign->pivot_role,
                    'status' => $campaign->pivot_status,
                ],
            ]);

        return [
            'owned' => $owned->toArray(),
            'participated' => $participated->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherEvents(User $user): array
    {
        $organized = $user->organizedEvents()->get()->map(fn (Event $event) => [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'status' => $event->status?->value,
            'starts_at' => $event->start_date?->toIso8601String(),
            'ends_at' => $event->end_date?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ]);
        $registrations = $user->eventRegistrations()
            ->with('event')
            ->get()
            ->map(
                fn (EventRegistration $reg) => [
                    'id' => $reg->id,
                    'event_id' => $reg->event_id,
                    'event_name' => $reg->event?->name,
                    'registration_type' => $reg->registration_type,
                    'division' => $reg->division,
                    'status' => $reg->status,
                    'payment_status' => $reg->payment_status,
                    'confirmed_at' => $reg->confirmed_at?->toIso8601String(),
                    'cancelled_at' => $reg->cancelled_at?->toIso8601String(),
                    'created_at' => $reg->created_at?->toIso8601String(),
                ],
            );

        return [
            'organized' => $organized->toArray(),
            'registered' => $registrations->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherReviews(User $user): array
    {
        // Reviews written by this user — their own content
        $written = Review::where('reviewer_id', $user->id)->get()->map(fn (Review $review) => [
            'id' => $review->id,
            'rating' => $review->rating,
            'content' => $review->content,
            'status' => $review->status,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ]);

        // Reviews received as GM — only include the review content, not the reviewer's PII
        $received = Review::whereHas('gmProfile', fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'content' => $review->content,
                'status' => $review->status,
                'created_at' => $review->created_at?->toIso8601String(),
            ]);

        return [
            'written' => $written->toArray(),
            'received_as_gm' => $received->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherTeams(User $user): array
    {
        return $user->teams()->get()->map(
            fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'created_at' => $team->created_at?->toIso8601String(),
                'pivot' => [
                    'role' => $team->pivot?->role,
                    'status' => $team->pivot?->status,
                    'jersey_number' => $team->pivot?->jersey_number,
                    'position' => $team->pivot?->position,
                    'joined_at' => $team->pivot?->joined_at ? Carbon::parse($team->pivot->joined_at)->toIso8601String() : null,
                    'left_at' => $team->pivot?->left_at ? Carbon::parse($team->pivot->left_at)->toIso8601String() : null,
                ],
            ],
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherActivityLog(User $user): array
    {
        return ActivityLog::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'event_type' => $log->event_type?->value,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherPushSubscriptions(User $user): array
    {
        return $user->pushSubscriptions()
            ->get()
            ->map(fn (PushSubscription $sub) => [
                'id' => $sub->id,
                // Only export the push service hostname, not the full endpoint URL.
                // The endpoint contains a bearer token that could be abused to
                // send unauthorized push notifications if the export is intercepted.
                'push_service_host' => $sub->endpoint ? parse_url($sub->endpoint, PHP_URL_HOST) : null,
                'created_at' => $sub->created_at?->toIso8601String(),
                'updated_at' => $sub->updated_at?->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherSocialLinks(User $user): array
    {
        return $user->gmSocialLinks()
            ->get()
            ->map(fn (GmSocialLink $link) => [
                'id' => $link->id,
                'platform' => $link->platform,
                'handle' => $link->handle,
                'instance' => $link->instance,
                'url' => $link->url,
                'created_at' => $link->created_at?->toIso8601String(),
                'updated_at' => $link->updated_at?->toIso8601String(),
            ])
            ->toArray();
    }

    // ── Media ─────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function copyMediaFiles(User $user, string $tempDir): array
    {
        $checksums = [];
        $mediaDir = $tempDir.'/media';

        $media = $user->getMedia();

        if ($media->isEmpty()) {
            return $checksums;
        }

        File::makeDirectory($mediaDir);

        foreach ($media as $item) {
            $sourcePath = $item->getPath();

            if (! File::exists($sourcePath)) {
                continue;
            }

            $destName = $item->collection_name.'/'.$item->file_name;
            $destDir = $mediaDir.'/'.$item->collection_name;

            if (! File::exists($destDir)) {
                File::makeDirectory($destDir);
            }

            File::copy($sourcePath, $destDir.'/'.$item->file_name);
            $checksums['media/'.$destName] = hash_file('sha256', $destDir.'/'.$item->file_name);
        }

        return $checksums;
    }

    // ── Manifest ──────────────────────────────────────

    /**
     * @param  array<string, string>  $fileChecksums
     * @param  array<string, mixed>  $fileChecksums
     * @return array<string, mixed>
     */
    protected function buildManifest(User $user, array $fileChecksums): array
    {
        return [
            'export_date' => now()->toIso8601String(),
            'user_id' => $user->id,
            'schema_version' => self::SCHEMA_VERSION,
            'files' => collect($fileChecksums)->map(fn ($hash, $path) => [
                'path' => $path,
                'sha256' => $hash,
            ])->values()->toArray(),
        ];
    }

    // ── File Operations ───────────────────────────────

    protected function createTempDirectory(string $userId): string
    {
        $tempDir = storage_path('app/tmp/export-'.$userId.'-'.now()->format('Ymd-His').'-'.Str::random(8));

        File::ensureDirectoryExists($tempDir);

        return $tempDir;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeJson(string $dir, string $filename, array $data): string
    {
        $path = $dir.'/'.$filename;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($path, $json !== false ? $json : '{}');

        return hash_file('sha256', $path) ?: '';
    }

    protected function createZipArchive(string $sourceDir, User $user): string
    {
        $zipFilename = 'user-data-'.$user->id.'-'.now()->format('Ymd-His').'-'.Str::random(8).'.zip';
        $zipPath = storage_path('app/tmp/'.$zipFilename);

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP archive at {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }
            $filePath = $file->getRealPath();
            if (! is_string($filePath)) {
                continue;
            }
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }

        if (! $zip->close()) {
            throw new \RuntimeException("Failed to create ZIP archive at {$zipPath}: ".$zip->getStatusString());
        }

        return $zipPath;
    }

    protected function storeZip(string $zipPath, User $user): string
    {
        $storedName = 'exports/user-data-'.$user->id.'-'.now()->format('Ymd-His').'-'.Str::random(8).'.zip';

        $disk = Storage::disk('local');

        // Stream the file to avoid loading the entire ZIP into memory.
        $stream = fopen($zipPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot read ZIP file at {$zipPath}");
        }

        $disk->writeStream($storedName, $stream);
        fclose($stream);

        // Clean up the temp ZIP
        File::delete($zipPath);

        return $storedName;
    }
}
