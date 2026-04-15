<?php

namespace App\Models;

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
        ];
    }

    // ── Relationships ──────────────────────────────────

    public function linkedAccounts()
    {
        return $this->hasMany(LinkedAccount::class);
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
