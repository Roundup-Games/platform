<?php

namespace App\Services;

use App\Models\GmSocialLink;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GmSocialLinkService
{
    /**
     * Generate the full URL for a platform handle.
     * Resolves the url_template from config, substituting {handle} and {instance}.
     */
    public function generateUrl(string $platform, string $handle, ?string $instance = null): ?string
    {
        $config = config("platforms.{$platform}");

        if (! $config) {
            Log::warning('gm_social_link.unknown_platform', [
                'platform' => $platform,
                'action' => 'generate_url',
            ]);

            return null;
        }

        $url = $config['url_template'];
        $url = str_replace('{handle}', $handle, $url);

        if (str_contains($url, '{instance}')) {
            $url = str_replace('{instance}', $instance ?? '', $url);
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

        if (! $config) {
            return ['valid' => false, 'error' => "Unknown platform: {$platform}"];
        }

        if (empty($handle)) {
            return ['valid' => false, 'error' => 'Handle is required.'];
        }

        $pattern = $config['handle_pattern'] ?? '//';

        if (! preg_match($pattern, $handle)) {
            Log::info('gm_social_link.invalid_handle', [
                'platform' => $platform,
                'handle' => $handle,
                'pattern' => $pattern,
            ]);

            return ['valid' => false, 'error' => "Invalid handle for {$config['name']}."];
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
        $pattern = $mastodonConfig['instance_pattern'] ?? '//';

        if (! preg_match($pattern, $instance)) {
            return ['valid' => false, 'error' => 'Invalid instance domain.'];
        }

        return ['valid' => true];
    }

    /**
     * Get all platforms sorted by sort_order from config.
     *
     * @return array<string, array>
     */
    public function getPlatforms(): array
    {
        $platforms = config('platforms', []);

        uasort($platforms, fn ($a, $b) => ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999));

        return $platforms;
    }

    /**
     * Sync social links for a user — creates, updates, and deletes as needed.
     *
     * Each item in $links should have: platform, handle, and optionally instance.
     * Items with an empty handle are treated as deletions.
     *
     * @param  array<int, array{platform: string, handle: string, instance?: string}>  $links
     * @return array{synced: int, errors: array<string, string>}
     */
    public function syncLinksForUser($user, array $links): array
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
                GmSocialLink::where('user_id', $user->id)
                    ->where('platform', $platform)
                    ->delete();

                Log::info('gm_social_link.deleted', [
                    'user_id' => $user->id,
                    'platform' => $platform,
                ]);

                continue;
            }

            // Validate handle
            $validation = $this->validateHandle($platform, $handle);
            if (! $validation['valid']) {
                $errors[$platform] = $validation['error'];
                continue;
            }

            // Validate instance if required
            $platformConfig = config("platforms.{$platform}");
            if (($platformConfig['instance_required'] ?? false) && $instance) {
                $instanceValidation = $this->validateInstance($instance);
                if (! $instanceValidation['valid']) {
                    $errors[$platform] = $instanceValidation['error'];
                    continue;
                }
            }

            // Upsert the link
            GmSocialLink::updateOrCreate(
                ['user_id' => $user->id, 'platform' => $platform],
                ['handle' => $handle],
            );

            // If instance is stored, we may need to regenerate URL with it
            if (($platformConfig['instance_required'] ?? false) && $instance) {
                $linkModel = GmSocialLink::where('user_id', $user->id)
                    ->where('platform', $platform)
                    ->first();
                if ($linkModel) {
                    $linkModel->url = $this->generateUrl($platform, $handle, $instance);
                    $linkModel->save();
                }
            }

            Log::info('gm_social_link.synced', [
                'user_id' => $user->id,
                'platform' => $platform,
                'handle' => $handle,
            ]);

            $synced++;
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * Get the full display URL for a social link.
     * Falls back to the stored url, regenerating if needed.
     */
    public function getDisplayUrl(GmSocialLink $link): ?string
    {
        if ($link->url) {
            return $link->url;
        }

        return $this->generateUrl($link->platform, $link->handle);
    }
}
