<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GmSocialLink;
use App\Models\LinkedAccount;
use App\Models\PushSubscription;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * Files to exclude from the user model attributes.
     */
    protected const PROFILE_EXCLUDED_ATTRIBUTES = [
        'password',
        'remember_token',
        'paddle_id',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userUuid = $this->argument('user');

        $user = User::withoutGlobalScope('not-anonymized')
            ->where('id', $userUuid)
            ->first();

        if (! $user) {
            $this->error("User with UUID [{$userUuid}] not found.");

            return self::FAILURE;
        }

        $this->info("Generating data export for user [{$user->name}] ({$user->id})");

        $tempDir = $this->createTempDirectory($user->id);

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
            $this->info('File size: '.$this->formatBytes($fileSize));

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

    protected function gatherProfile(User $user): array
    {
        $attributes = $user->attributesToArray();

        foreach (self::PROFILE_EXCLUDED_ATTRIBUTES as $key) {
            unset($attributes[$key]);
        }

        // Remove pivot-related internal keys
        unset($attributes['pivot']);

        return $attributes;
    }

    protected function gatherLinkedAccounts(User $user): array
    {
        return $user->linkedAccounts()
            ->get()
            ->map(fn (LinkedAccount $account) => [
                'id' => $account->id,
                'provider' => $account->provider,
                'provider_user_id' => $account->provider_user_id,
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
                'provider_meta' => $account->provider_meta,
                'created_at' => $account->created_at?->toIso8601String(),
                'updated_at' => $account->updated_at?->toIso8601String(),
            ])
            ->toArray();
    }

    protected function gatherGames(User $user): array
    {
        $owned = $user->ownedGames()->get()->map($this->modelToArray(...));

        // Build participation query manually to avoid withTimestamps() expecting updated_at
        $participated = Game::query()
            ->join('game_participants', 'games.id', '=', 'game_participants.game_id')
            ->where('game_participants.user_id', $user->id)
            ->select('games.*', 'game_participants.role as pivot_role', 'game_participants.status as pivot_status')
            ->get()
            ->map(fn ($game) => array_merge($this->modelToArray($game), [
                'pivot' => [
                    'role' => $game->pivot_role,
                    'status' => $game->pivot_status,
                ],
            ]));

        return [
            'owned' => $owned->toArray(),
            'participated' => $participated->toArray(),
        ];
    }

    protected function gatherCampaigns(User $user): array
    {
        $owned = $user->ownedCampaigns()->get()->map($this->modelToArray(...));

        // Build participation query manually to avoid withTimestamps() expecting updated_at
        $participated = Campaign::query()
            ->join('campaign_participants', 'campaigns.id', '=', 'campaign_participants.campaign_id')
            ->where('campaign_participants.user_id', $user->id)
            ->select('campaigns.*', 'campaign_participants.role as pivot_role', 'campaign_participants.status as pivot_status')
            ->get()
            ->map(fn ($campaign) => array_merge($this->modelToArray($campaign), [
                'pivot' => [
                    'role' => $campaign->pivot_role,
                    'status' => $campaign->pivot_status,
                ],
            ]));

        return [
            'owned' => $owned->toArray(),
            'participated' => $participated->toArray(),
        ];
    }

    protected function gatherEvents(User $user): array
    {
        $organized = $user->organizedEvents()->get()->map($this->modelToArray(...));
        $registrations = $user->eventRegistrations()->get()->map(
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

    protected function gatherReviews(User $user): array
    {
        $written = Review::where('reviewer_id', $user->id)->get()->map($this->modelToArray(...));
        $received = Review::whereHas('gmProfile', fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->map($this->modelToArray(...));

        return [
            'written' => $written->toArray(),
            'received_as_gm' => $received->toArray(),
        ];
    }

    protected function gatherTeams(User $user): array
    {
        return $user->teams()->get()->map(
            fn (Team $team) => array_merge($this->modelToArray($team), [
                'pivot' => [
                    'role' => $team->pivot->role,
                    'status' => $team->pivot->status,
                    'jersey_number' => $team->pivot->jersey_number,
                    'position' => $team->pivot->position,
                    'joined_at' => $team->pivot->joined_at,
                    'left_at' => $team->pivot->left_at,
                ],
            ]),
        )->toArray();
    }

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
                'properties' => $log->properties,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->toArray();
    }

    protected function gatherPushSubscriptions(User $user): array
    {
        return $user->pushSubscriptions()
            ->get()
            ->map(fn (PushSubscription $sub) => [
                'id' => $sub->id,
                'endpoint' => $sub->endpoint,
                'user_agent' => $sub->user_agent,
                'created_at' => $sub->created_at?->toIso8601String(),
                'updated_at' => $sub->updated_at?->toIso8601String(),
            ])
            ->toArray();
    }

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
        $tempDir = storage_path('app/tmp/export-'.$userId.'-'.now()->format('Ymd-His'));

        File::ensureDirectoryExists($tempDir);

        return $tempDir;
    }

    protected function writeJson(string $dir, string $filename, array $data): string
    {
        $path = $dir.'/'.$filename;
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return hash_file('sha256', $path);
    }

    protected function createZipArchive(string $sourceDir, User $user): string
    {
        $zipFilename = 'user-data-'.$user->id.'-'.now()->format('Ymd-His').'.zip';
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
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();

        return $zipPath;
    }

    protected function storeZip(string $zipPath, User $user): string
    {
        $storedName = 'exports/user-data-'.$user->id.'-'.now()->format('Ymd-His').'.zip';

        $disk = Storage::disk('local');
        $disk->put($storedName, file_get_contents($zipPath));

        // Clean up the temp ZIP
        File::delete($zipPath);

        return $storedName;
    }

    // ── Helpers ───────────────────────────────────────

    protected function modelToArray(mixed $model): array
    {
        if (method_exists($model, 'attributesToArray')) {
            return $model->attributesToArray();
        }

        return (array) $model;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
