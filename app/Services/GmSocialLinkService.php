<?php

namespace App\Services;

use App\Models\GmSocialLink;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class GmSocialLinkService
{
    /**
     * Generate the full URL for a platform handle.
     * Resolves the url_template from config, substituting {handle} and {instance}.
     *
     * Returns null if the platform is unknown or the resulting URL is not https/http.
     */
    public function generateUrl(string $platform, string $handle, ?string $instance = null): ?string
    {
        $config = config("platforms.{$platform}");

        if (! is_array($config)) {
            Log::warning('gm_social_link.unknown_platform', [
                'platform' => $platform, 'action' => 'generate_url',
            ]);

            return null;
        }

        $url = is_string($config['url_template'] ?? null) ? $config['url_template'] : '';

        if (str_contains($url, '{instance}')) {
            $instanceRequired = $config['instance_required'] ?? false;
            if ($instanceRequired && empty($instance)) {
                return null;
            }
            $url = str_replace('{instance}', $instance ?? '', $url);
        }

        $url = str_replace('{handle}', $handle, $url);

        // Defense-in-depth: reject URLs that don't have a safe scheme.
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            Log::warning('gm_social_link.unsafe_url_generated', [
                'platform' => $platform, 'handle' => $handle, 'url' => $url,
            ]);

            return null;
        }

        return $url;
    }

    /**
     * Validate a handle against the platform's handle_pattern from config.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateHandle(string $platform, string $handle): array
    {
        $config = config("platforms.{$platform}");

        if (! is_array($config)) {
            return ['valid' => false, 'error' => "Unknown platform: {$platform}"];
        }

        if (empty($handle)) {
            return ['valid' => false, 'error' => 'Handle is required.'];
        }

        $pattern = is_string($config['handle_pattern'] ?? null) ? $config['handle_pattern'] : null;

        if (! is_string($pattern) || $pattern === '' || @preg_match($pattern, '') === false) {
            Log::warning('gm_social_link.invalid_handle_pattern_config', [
                'platform' => $platform, 'pattern' => $pattern,
            ]);

            return ['valid' => false, 'error' => "Platform {$platform} is misconfigured."];
        }

        if (! preg_match($pattern, $handle)) {
            Log::info('gm_social_link.invalid_handle', [
                'platform' => $platform, 'handle' => $handle, 'pattern' => $pattern,
            ]);

            $platformName = is_string($config['name'] ?? null) ? $config['name'] : $platform;

            return ['valid' => false, 'error' => "Invalid handle for {$platformName}."];
        }

        return ['valid' => true];
    }

    /**
     * Validate a Mastodon-style instance domain.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateInstance(string $instance): array
    {
        $mastodonConfig = config('platforms.mastodon');
        $pattern = is_array($mastodonConfig) && is_string($mastodonConfig['instance_pattern'] ?? null)
            ? $mastodonConfig['instance_pattern']
            : null;

        if (! is_string($pattern) || $pattern === '' || @preg_match($pattern, '') === false) {
            return ['valid' => false, 'error' => 'Instance validation is temporarily unavailable.'];
        }

        if (! preg_match($pattern, $instance)) {
            return ['valid' => false, 'error' => 'Invalid instance domain.'];
        }

        return ['valid' => true];
    }

    /**
     * Sync social links for a user — creates, updates, and deletes as needed.
     *
     * Each item in $links should have: platform, handle, and optionally instance.
     * Items with an empty handle are treated as deletions.
     * Platforms present in config but absent from $links are also removed.
     *
     * @param  array<int, array{platform?: string, handle?: string, instance?: string}>  $links
     * @return array{synced: int, errors: array<string, string>}
     */
    public function syncLinksForUser(User $user, array $links): array
    {
        $synced = 0;
        $errors = [];
        $submittedPlatforms = [];

        foreach ($links as $link) {
            $platform = $link['platform'] ?? '';
            $handle = trim($link['handle'] ?? '');
            $instance = trim($link['instance'] ?? '');

            if (! $platform) {
                continue;
            }

            $submittedPlatforms[] = $platform;

            // Empty handle = remove the link
            if ($handle === '') {
                $this->deleteLink($user, $platform);

                continue;
            }

            // Validate handle
            $validation = $this->validateHandle($platform, $handle);
            if (! $validation['valid']) {
                $errors[$platform] = $validation['error'] ?? 'Invalid handle.';

                continue;
            }

            // Validate instance if required
            $platformConfig = config("platforms.{$platform}");
            $instanceRequired = is_array($platformConfig) && ($platformConfig['instance_required'] ?? false);
            if ($instanceRequired && empty($instance)) {
                $errors[$platform] = 'Instance is required for this platform.';

                continue;
            }

            if (! empty($instance)) {
                $instanceValidation = $this->validateInstance($instance);
                if (! $instanceValidation['valid']) {
                    $errors[$platform] = $instanceValidation['error'] ?? 'Invalid instance.';

                    continue;
                }
            }

            // Upsert the link — include instance so the model's saving event
            // can regenerate the URL from platform + handle + instance.
            GmSocialLink::updateOrCreate(
                ['user_id' => $user->id, 'platform' => $platform],
                [
                    'handle' => $handle,
                    'instance' => $instance ?: null,
                ],
            );

            Log::info('gm_social_link.synced', [
                'user_id' => $user->id, 'platform' => $platform, 'handle' => $handle,
            ]);

            $synced++;
        }

        // Remove links for known platforms that weren't submitted (user unchecked them).
        $platformsConfig = config('platforms', []);
        $knownPlatforms = is_array($platformsConfig) ? array_keys($platformsConfig) : [];
        $orphanPlatforms = array_diff($knownPlatforms, $submittedPlatforms);
        foreach ($orphanPlatforms as $platform) {
            $this->deleteLink($user, (string) $platform);
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * Delete a social link for a user/platform pair.
     */
    private function deleteLink(User $user, string $platform): void
    {
        $deleted = GmSocialLink::whereBelongsTo($user)
            ->where('platform', $platform)
            ->delete();

        if ($deleted > 0) {
            Log::info('gm_social_link.deleted', [
                'user_id' => $user->id, 'platform' => $platform,
            ]);
        }
    }
}
