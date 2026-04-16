<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use App\Enums\VibeFlag;
use App\Services\ScopedRoleService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Notifications\Notifiable;
use Laravel\Paddle\Billable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'password',
    'email_verified_at',
    'avatar_url',
    'profile_complete',
    'gender',
    'pronouns',
    'phone',
    'privacy_settings',
    'profile_version',
    'profile_updated_at',
    'password_set_at',
    'is_disabled',
    'disabled_at',
    'can_create_public_entries',
    'preferred_language',
    'location',
    'location_id',
])]
#[Hidden(['password', 'remember_token', 'paddle_id'])]
class User extends Authenticatable implements FilamentUser, HasMedia
{
    use Billable;
    use HasFactory;
    use HasRoles;
    use InteractsWithMedia;
    use Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile_complete' => 'boolean',
            'privacy_settings' => 'array',
            'profile_version' => 'integer',
            'profile_updated_at' => 'datetime',
            'password_set_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'is_disabled' => 'boolean',
            'disabled_at' => 'datetime',
            'can_create_public_entries' => 'boolean',
            'preferred_language' => ContentLanguage::class,
            'location' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────

    public function linkedAccounts()
    {
        return $this->hasMany(LinkedAccount::class);
    }

    public function linkedLocation()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->using(TeamMember::class)
            ->withPivot(['role', 'status', 'jersey_number', 'position', 'joined_at', 'left_at', 'invited_by', 'notes']);
    }

    public function activeTeam()
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->using(TeamMember::class)
            ->wherePivot('status', 'active')
            ->withPivot(['role', 'status', 'jersey_number', 'position', 'joined_at'])
            ->orderByPivot('joined_at', 'desc')
            ->limit(1);
    }

    public function ownedGames()
    {
        return $this->hasMany(Game::class, 'owner_id');
    }

    public function gameParticipations()
    {
        return $this->belongsToMany(Game::class, 'game_participants')
            ->using(GameParticipant::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function gameApplications()
    {
        return $this->belongsToMany(Game::class, 'game_applications')
            ->using(GameApplication::class)
            ->withPivot(['status', 'message'])
            ->withTimestamps();
    }

    public function ownedCampaigns()
    {
        return $this->hasMany(Campaign::class, 'owner_id');
    }

    public function campaignParticipations()
    {
        return $this->belongsToMany(Campaign::class, 'campaign_participants')
            ->using(CampaignParticipant::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function campaignApplications()
    {
        return $this->belongsToMany(Campaign::class, 'campaign_applications')
            ->using(CampaignApplication::class)
            ->withPivot(['status', 'message'])
            ->withTimestamps();
    }

    public function organizedEvents()
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    public function eventRegistrations()
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function gameSystemPreferences()
    {
        return $this->belongsToMany(GameSystem::class, 'user_game_system_preferences')
            ->withPivot('preference_type');
    }

    public function favoriteGameSystems()
    {
        return $this->belongsToMany(GameSystem::class, 'user_game_system_preferences')
            ->wherePivot('preference_type', 'favorite');
    }

    public function avoidedGameSystems()
    {
        return $this->belongsToMany(GameSystem::class, 'user_game_system_preferences')
            ->wherePivot('preference_type', 'avoid');
    }

    public function vibePreferences()
    {
        return $this->hasMany(UserVibePreference::class);
    }

    public function favoriteVibes()
    {
        return $this->hasMany(UserVibePreference::class)
            ->where('preference_type', 'favorite');
    }

    public function avoidedVibes()
    {
        return $this->hasMany(UserVibePreference::class)
            ->where('preference_type', 'avoid');
    }

    // ── Preference Resolution ─────────────────────────

    /**
     * Resolved game-system preferences including base/expansion implications.
     *
     * Rules:
     *  - Favorited base games imply all their expansions as 'implied_favorites'.
     *  - If a system is both favorited (or implied) AND explicitly avoided,
     *    the explicit avoid wins.
     *  - Handles circular safety: a system can be both a base (has expansions)
     *    and an expansion (has base_game_id).
     *
     * @return array{favorites: \Illuminate\Database\Eloquent\Collection<int, GameSystem>, avoided: \Illuminate\Database\Eloquent\Collection<int, GameSystem>, implied_favorites: \Illuminate\Database\Eloquent\Collection<int, GameSystem>}
     */
    public function resolvedGameSystemPreferences(): array
    {
        $favorites = $this->favoriteGameSystems()->get();
        $avoided = $this->avoidedGameSystems()->get();
        $avoidedIds = $avoided->pluck('id')->flip();

        // Collect implied favorites from expansions of favorited base games
        // Only include expansions that are NOT explicitly avoided (avoid wins)
        $impliedIds = collect();
        foreach ($favorites as $system) {
            foreach ($system->expansions as $expansion) {
                if (! $avoidedIds->has($expansion->id)) {
                    $impliedIds->put($expansion->id, $expansion);
                }
            }
        }

        $impliedFavorites = $impliedIds;

        // Remove any favorites that are explicitly avoided
        $resolvedFavorites = $favorites->reject(
            fn (GameSystem $sys) => $avoidedIds->has($sys->id),
        );

        // Collect implied avoids from expansions of avoided base games
        $impliedAvoidIds = collect();
        foreach ($avoided as $system) {
            foreach ($system->expansions as $expansion) {
                $impliedAvoidIds->put($expansion->id, $expansion);
            }
        }

        // Merge explicit avoids with implied avoids (implied only if not explicitly favorited)
        $allAvoided = $avoided->keyBy('id');
        foreach ($impliedAvoidIds as $id => $expansion) {
            if (! $resolvedFavorites->keyBy('id')->has($id) && ! $impliedFavorites->has($id)) {
                $allAvoided->put($id, $expansion);
            }
        }

        return [
            'favorites' => $resolvedFavorites,
            'avoided' => $allAvoided->values(),
            'implied_favorites' => $impliedFavorites,
        ];
    }

    /**
     * Resolved vibe preferences with mutual-exclusivity enforcement.
     *
     * Rules:
     *  - For each favorite, its exclusive partner is auto-avoided.
     *  - For each avoid, its exclusive partner is NOT auto-favorited.
     *  - Deduplication: if a flag is both explicitly favorite and auto-avoided,
     *    explicit favorite wins (the partner goes to avoided instead).
     *
     * @return array{favorites: string[], avoided: string[]}
     */
    public function resolvedVibePreferences(): array
    {
        $explicitFavorites = $this->favoriteVibes()
            ->pluck('vibe_preference_value')
            ->map(fn (VibeFlag $flag) => $flag->value)
            ->unique()
            ->values()
            ->all();

        $explicitAvoids = $this->avoidedVibes()
            ->pluck('vibe_preference_value')
            ->map(fn (VibeFlag $flag) => $flag->value)
            ->unique()
            ->values()
            ->all();

        $favoriteSet = array_flip($explicitFavorites);
        $avoidSet = array_flip($explicitAvoids);

        // Build a lookup: flag value => partner flag value
        $partnerLookup = [];
        foreach (VibeFlag::mutuallyExclusivePairs() as [$a, $b]) {
            $partnerLookup[$a->value] = $b->value;
            $partnerLookup[$b->value] = $a->value;
        }

        // Auto-avoid partners of favorites
        foreach ($explicitFavorites as $fav) {
            if (isset($partnerLookup[$fav]) && ! isset($favoriteSet[$partnerLookup[$fav]])) {
                $avoidSet[$partnerLookup[$fav]] = true;
            }
        }

        // Remove from avoid set anything that's explicitly favorited (favorite wins)
        foreach ($explicitFavorites as $fav) {
            unset($avoidSet[$fav]);
        }

        return [
            'favorites' => array_values(array_keys($favoriteSet)),
            'avoided' => array_values(array_keys($avoidSet)),
        ];
    }

    // ── Spatie Media Library ──────────────────────────

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);
    }

    // ── Helpers ────────────────────────────────────────

    public function hasActiveMembership(): bool
    {
        return $this->subscribed();
    }

    /**
     * Determine if the user has intentionally set a password.
     * OAuth-only users have password_set_at null (and possibly password null).
     */
    public function hasPasswordSet(): bool
    {
        return $this->password_set_at !== null && $this->password !== null;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('Platform Admin') || $this->hasRole('Games Admin');
    }

    public function isDisabled(): bool
    {
        return (bool) $this->is_disabled;
    }

    public function isTeamCaptain(Team $team): bool
    {
        return $this->teams()
            ->where('teams.id', $team->id)
            ->wherePivot('role', 'captain')
            ->wherePivot('status', 'active')
            ->exists();
    }

    /**
     * Determine if the user can access the Filament admin panel.
     *
     * Only global admin users (Platform Admin, Games Admin) may access the panel.
     * Resource-level access is further controlled by policies.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return app(ScopedRoleService::class)->isGlobalAdmin($this)
            || $this->hasRole('Platform Admin');
    }
}
