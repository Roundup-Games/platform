<?php

namespace App\Livewire\Profile;

use App\Enums\ContentLanguage;
use App\Enums\OAuthProvider;
use App\Enums\VibeFlag;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\Location;
use App\Models\User;
use App\Rules\ValidUserName;
use App\Services\DashboardCacheService;
use App\Services\GmSocialLinkService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class Show extends Component
{
    use WithFileUploads;

    // Profile fields
    public string $name = '';

    public string $email = '';

    public string $slug = '';

    public string $gender = '';

    public bool $gender_consent = false;

    public string $pronouns = '';

    public string $phone = '';

    public string $preferredLanguage = '';

    public string $bio = '';

    public ?string $locationId = null;

    /** @var array<int|string, mixed> */
    public array $favoriteGameSystemIds = [];

    /** @var array<int|string, mixed> */
    public array $avoidedGameSystemIds = [];

    /** @var array<int|string, mixed> Map of VibeFlag value → null|'favorite'|'avoid' */
    public array $vibePreferences = [];

    #[Validate(['nullable', 'image', 'max:1024'])]
    public ?TemporaryUploadedFile $avatar = null;

    public bool $saved = false;

    public bool $preferencesSaved = false;

    public bool $socialLinksSaved = false;

    /** @var array<string, array{handle: string|null, instance?: string|null}> Per-platform social link data */
    public array $socialLinks = [];

    /** @var array<int|string, mixed> Platform config for rendering */
    public array $platforms = [];

    /**
     * Discord user ID (snowflake) from the GM's linked Discord account, or null
     * when no Discord account is linked. Drives the 'Use my Discord' prefill
     * button in the GM social-links form (M056 Q1 user decision).
     */
    public ?string $discordLinkedUserId = null;

    /**
     * True when the Discord handle was auto-populated from the linked Discord
     * account on form mount (M056/S03/T11). The GM's existing GmSocialLink
     * handle takes precedence; auto-fill only fires for first-mount convenience.
     * Drives the 'Undo auto-fill' affordance in the social-links form.
     */
    public bool $discordAutofilled = false;

    public function mount(): void
    {
        $user = authenticatedUser();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->slug = $user->slug ?? '';
        $this->gender = $user->gender ?? '';
        $this->gender_consent = (bool) $user->gender_consent;
        $this->pronouns = $user->pronouns ?? '';
        $this->phone = $user->phone ?? '';
        $this->preferredLanguage = $user->preferred_language->value ?? '';
        $this->bio = $user->bio ?? '';
        $this->locationId = $user->location_id;
        $this->favoriteGameSystemIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'favorite')
            ->pluck('game_systems.id')
            ->toArray();
        $this->avoidedGameSystemIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'avoid')
            ->pluck('game_systems.id')
            ->toArray();

        // Load existing vibe preferences
        $this->vibePreferences = $user->vibePreferences?->mapWithKeys(function ($pref) {
            return [$pref->vibe_preference_value->value => $pref->preference_type];
        })->toArray() ?? [];

        // Load social links for GMs
        /** @var array<string, array{name: string, icon: string, sort_order: int}> $platformsConfig */
        $platformsConfig = config('platforms');
        $this->platforms = collect($platformsConfig)
            ->sortBy('sort_order')
            ->toArray();

        if ($user->isGM()) {
            $this->loadSocialLinks($user);

            // Resolve the GM's linked Discord account (if any) so the
            // 'Use my Discord' prefill button can populate the Discord handle
            // field with their numeric Discord user ID (M056 Q1 user decision).
            $discordAccount = $user->linkedAccounts()
                ->where('provider', OAuthProvider::Discord)
                ->first();
            if ($discordAccount) {
                $this->discordLinkedUserId = (string) $discordAccount->provider_user_id;

                // M056/S03/T11: auto-fill the Discord handle on mount when the GM
                // has a linked Discord account and no existing GmSocialLink handle.
                // Existing handle takes precedence — the auto-fill is strictly a
                // first-mount convenience. The 'Use my Discord' button below the
                // field remains as a re-apply / undo affordance after manual edits.
                // isset() covers the null + undefined cases; the '' check covers
                // the empty-string case. No separate null test is needed (and
                // would be dead code — isset already filtered null out).
                if (! isset($this->socialLinks['discord']['handle'])
                    || $this->socialLinks['discord']['handle'] === ''
                ) {
                    $this->socialLinks['discord']['handle'] = $this->discordLinkedUserId;
                    $this->discordAutofilled = true;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $selectedIds
     */
    public function selectionChanged(string $preferenceType, array $selectedIds): void
    {
        if ($preferenceType === 'favorite') {
            $this->favoriteGameSystemIds = array_values($selectedIds);
        } elseif ($preferenceType === 'avoid') {
            $this->avoidedGameSystemIds = array_values($selectedIds);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getListeners(): array
    {
        return [
            'selection-changed' => 'selectionChanged',
            'vibe-preferences-changed' => 'vibePreferencesChanged',
        ];
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    public function vibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
    }

    /**
     * Receive location selection from the LocationPicker component.
     */
    #[On('location-selected')]
    public function onLocationSelected(string $locationId, string $city, ?string $address = null): void
    {
        $this->locationId = $locationId;
    }

    /**
     * Handle location removal from the LocationPicker component.
     */
    #[On('location-removed')]
    public function onLocationRemoved(): void
    {
        $this->locationId = null;
    }

    public function saveProfile(): void
    {
        $user = authenticatedUser();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', new ValidUserName],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'gender' => ['nullable', 'string', 'max:50'],
            'gender_consent' => ['boolean'],
            'pronouns' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'preferredLanguage' => ['nullable', 'string', 'in:'.implode(',', ContentLanguage::values())],
            'bio' => ['nullable', 'string', 'max:500'],
        ]);

        $emailChanged = $user->email !== $validated['email'];

        // Capture pre-update location for discovery cache change detection
        $oldLocationId = $user->location_id;

        // GDPR Art. 9(2)(a): only store gender if explicit consent is given
        $genderValue = $validated['gender_consent'] ? $validated['gender'] : null;

        // Audit trail for consent changes (GDPR Art. 7 compliance)
        $previousConsent = (bool) $user->gender_consent;
        $newConsent = $validated['gender_consent'];
        if ($previousConsent !== $newConsent) {
            Log::info('Gender consent status changed', [
                'user_id' => $user->id,
                'consent_given' => $newConsent,
                'gender_cleared' => $previousConsent && ! $newConsent,
            ]);
        }

        $user->update([
            'name' => ValidUserName::sanitize($validated['name']),
            'email' => $validated['email'],
            'gender' => $genderValue,
            'gender_consent' => $validated['gender_consent'],
            'pronouns' => $validated['pronouns'],
            'phone' => $validated['phone'],
            'preferred_language' => $validated['preferredLanguage'] ?: null,
            'bio' => $validated['bio'] ? trim(strip_tags($validated['bio'])) : null,
            'location_id' => $this->locationId,
            'profile_version' => ($user->profile_version ?? 0) + 1,
            'profile_updated_at' => now(),
        ]);

        if ($emailChanged) {
            $user->update(['email_verified_at' => null]);
            Log::info('Profile email changed', [
                'user_id' => $user->id,
                'email_changed' => true,
            ]);
        }

        if ($this->avatar) {
            $user->clearMediaCollection('avatar');
            $filename = $this->avatar->getClientOriginalName()
                ?: ('avatar.'.($this->avatar->extension() ?: 'jpg'));
            $user->addMedia($this->avatar->getRealPath())
                ->usingName($user->name.' avatar')
                ->usingFileName($filename)
                ->toMediaCollection('avatar');

            Log::info('Avatar uploaded', ['user_id' => $user->id]);
        }

        Log::info('Profile updated', [
            'user_id' => $user->id,
            'fields_updated' => array_keys($validated),
            'profile_version' => $user->profile_version,
        ]);

        // Dispatch discovery cache refresh if location changed
        if ($user->location_id !== ($oldLocationId ?? null)) {
            UpdateUserDiscoveryCache::dispatch((string) $user->id, 'location_change');

            // Invalidate dashboard caches affected by location change
            $dashboardCache = app(DashboardCacheService::class);
            $dashboardCache->invalidateForUser((string) $user->id, ['opportunities']);

            // Invalidate trending for old and new geohash tiles
            if ($oldLocationId) {
                $oldLocation = Location::find($oldLocationId);
                if ($oldLocation && $oldLocation->geohash_4) {
                    $dashboardCache->invalidateTrendingForGeohash($oldLocation->geohash_4);
                }
            }
            if ($user->linkedLocation && $user->linkedLocation->geohash_4) {
                $dashboardCache->invalidateTrendingForGeohash($user->linkedLocation->geohash_4);
            }
        }

        $this->saved = true;
    }

    public function savePreferences(): void
    {
        $user = authenticatedUser();

        $validated = $this->validate([
            'favoriteGameSystemIds' => ['array'],
            'favoriteGameSystemIds.*' => ['uuid', 'exists:game_systems,id'],
            'avoidedGameSystemIds' => ['array'],
            'avoidedGameSystemIds.*' => ['uuid', 'exists:game_systems,id'],
            'vibePreferences' => ['array'],
            'vibePreferences.*' => ['nullable', 'in:favorite,avoid'],
        ]);

        // Capture pre-update values for change detection BEFORE any writes
        $oldVibePreferences = $user->vibePreferences?->mapWithKeys(function ($pref) {
            return [$pref->vibe_preference_value->value => $pref->preference_type];
        })->toArray();
        $oldFavoriteIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'favorite')
            ->pluck('game_systems.id')
            ->sort()->values()->toArray();
        $oldAvoidedIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'avoid')
            ->pluck('game_systems.id')
            ->sort()->values()->toArray();

        // Sync game system preferences (favorites AND avoids)
        /** @var array<int, string> $favoriteIds */
        $favoriteIds = $validated['favoriteGameSystemIds'];
        $favoriteSync = collect($favoriteIds)->mapWithKeys(fn (string $id) => [$id => ['preference_type' => 'favorite'],
        ]);
        /** @var array<int, string> $avoidedIds */
        $avoidedIds = $validated['avoidedGameSystemIds'];
        $avoidSync = collect($avoidedIds)->mapWithKeys(fn (string $id) => [
            $id => ['preference_type' => 'avoid'],
        ]);
        $user->gameSystemPreferences()->sync(array_replace($favoriteSync->toArray(), $avoidSync->toArray()));

        // Save vibe preferences (atomic delete-and-insert within transaction)
        $validVibeValues = VibeFlag::values();
        DB::transaction(function () use ($user, $validVibeValues) {
            $user->vibePreferences()->delete();
            $inserts = [];
            foreach ($this->vibePreferences as $flagValue => $type) {
                if ($type !== null && in_array($type, ['favorite', 'avoid']) && in_array($flagValue, $validVibeValues)) {
                    $inserts[] = [
                        'user_id' => $user->id,
                        'vibe_preference_value' => $flagValue,
                        'preference_type' => $type,
                    ];
                }
            }
            if (! empty($inserts)) {
                $user->vibePreferences()->createMany($inserts);
            }
        });

        // Detect changes for discovery cache invalidation
        $vibesChanged = $oldVibePreferences !== $this->vibePreferences;
        /** @var array<int, string> $favoriteIdsForCompare */
        $favoriteIdsForCompare = $validated['favoriteGameSystemIds'];
        $newFavoriteIds = collect($favoriteIdsForCompare)->map('strval')->sort()->values()->toArray();
        /** @var array<int, string> $avoidedIdsForCompare */
        $avoidedIdsForCompare = $validated['avoidedGameSystemIds'];
        $newAvoidedIds = collect($avoidedIdsForCompare)->map('strval')->sort()->values()->toArray();
        $gameSystemsChanged = $oldFavoriteIds !== $newFavoriteIds || $oldAvoidedIds !== $newAvoidedIds;

        $user->update([
            'profile_version' => ($user->profile_version ?? 0) + 1,
            'profile_updated_at' => now(),
        ]);

        Log::info('Preferences updated', [
            'user_id' => $user->id,
            'favorite_game_systems_count' => count($validated['favoriteGameSystemIds']),
            'avoided_game_systems_count' => count($validated['avoidedGameSystemIds']),
            'vibe_favorites_count' => count(array_filter($this->vibePreferences, fn ($v) => $v === 'favorite')),
            'vibe_avoids_count' => count(array_filter($this->vibePreferences, fn ($v) => $v === 'avoid')),
            'profile_version' => $user->profile_version,
        ]);

        if ($vibesChanged) {
            UpdateUserDiscoveryCache::dispatch((string) $user->id, 'vibe_change');
        }
        if ($gameSystemsChanged) {
            UpdateUserDiscoveryCache::dispatch((string) $user->id, 'game_system_change');
        }

        // Invalidate dashboard opportunities cache when preferences change
        if ($vibesChanged || $gameSystemsChanged) {
            app(DashboardCacheService::class)->invalidateForUser(
                (string) $user->id, ['opportunities'],
            );
        }

        $this->preferencesSaved = true;
    }

    public function removeAvatar(): void
    {
        $user = authenticatedUser();
        $user->clearMediaCollection('avatar');

        Log::info('Avatar removed', ['user_id' => $user->id]);
    }

    /**
     * Load existing social links for the current user.
     */
    protected function loadSocialLinks(User $user): void
    {
        $existingLinks = $user->gmSocialLinks()->get()->keyBy('platform');

        foreach (array_keys($this->platforms) as $key) {
            if (! is_string($key)) {
                continue; // platform keys are always strings; skip defensively
            }
            $link = $existingLinks->get($key);
            $this->socialLinks[$key] = [
                'handle' => $link !== null ? $link->handle : '',
                'instance' => $link !== null ? ($link->instance ?? '') : '',
            ];
        }
    }

    /**
     * Prefill the Discord social-link handle with the GM's linked Discord user ID
     * (numeric snowflake). M056 Q1 user decision — one-click profile URL population
     * for GMs who have already linked their Discord account via OAuth.
     *
     * No-op when no Discord LinkedAccount exists; the button is only rendered
     * in that case, but the guard keeps the method safe against direct calls.
     */
    public function useMyDiscord(): void
    {
        $user = authenticatedUser();

        if (! $user->isGM() || ! $this->discordLinkedUserId) {
            return;
        }

        $this->socialLinks['discord']['handle'] = $this->discordLinkedUserId;
    }

    /**
     * Save social links for GM profile.
     */
    public function saveSocialLinks(): void
    {
        $user = authenticatedUser();

        if (! $user->isGM()) {
            return;
        }

        $rules = ['socialLinks' => ['required', 'array']];
        foreach ($this->platforms as $key => $platform) {
            $rules["socialLinks.{$key}.handle"] = ['nullable', 'string', 'max:255'];
            if (is_array($platform) && ($platform['instance_required'] ?? false)) {
                $rules["socialLinks.{$key}.instance"] = ['nullable', 'string', 'max:255'];
            }
        }

        $validated = $this->validate($rules);

        try {
            $service = app(GmSocialLinkService::class);
            // Transform keyed array ['twitter' => ['handle' => 'x']]
            // into the format syncLinksForUser expects: [['platform' => 'twitter', 'handle' => 'x']].
            /** @var array<string, array{handle: string, instance?: string}> $socialLinksData */
            $socialLinksData = $validated['socialLinks'];
            $links = collect($socialLinksData)->map(fn (array $data, string $platform): array => [
                'platform' => $platform,
                'handle' => $data['handle'],
                'instance' => (string) ($data['instance'] ?? ''),
            ])->values()->toArray();
            /** @var array<int, array{platform: string, handle: string, instance?: string}> $links */
            $service->syncLinksForUser($user, $links);

            Log::info('GM social links updated', [
                'user_id' => $user->id,
                'platforms_count' => count(array_filter($validated['socialLinks'], fn ($l) => ! empty($l['handle']))),
            ]);

            $this->socialLinksSaved = true;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to save GM social links', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->addError('socialLinks', __('profile.gm_social_links_error'));
        }
    }

    public function render(): View
    {
        $user = authenticatedUser();
        $locationRecord = $this->locationId ? Location::find($this->locationId) : null;

        return view('livewire.profile.show', [
            'currentLocation' => $locationRecord,
            'avatarMedia' => $user->getFirstMedia('avatar'),
        ]);
    }
}
