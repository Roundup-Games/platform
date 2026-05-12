<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use App\Enums\RelationshipType;
use App\Enums\VibeFlag;
use App\Services\ScopedRoleService;
use App\Services\SocialGraphService;
use App\Services\UserPreferenceResolver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Paddle\Billable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\StringMorphMediaKey;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\SchemaOrg\Person as SchemaPerson;

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
    'notification_settings',
    'reliability_score',
    'reliability_computed_at',
    'profile_version',
    'profile_updated_at',
    'password_set_at',
    'is_disabled',
    'disabled_at',
    'can_create_public_entries',
    'preferred_language',
    'location',
    'location_id',
    'bio',
    'slug',
])]
#[Hidden(['password', 'remember_token', 'paddle_id'])]
class User extends Authenticatable implements FilamentUser, HasMedia
{
    use Billable;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use HasSEO;
    use InteractsWithMedia;
    use StringMorphMediaKey { StringMorphMediaKey::media insteadof InteractsWithMedia; }
    use Notifiable;

    protected $keyType = 'string';
    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->id)) {
                $user->id = (string) Str::orderedUuid();
            }
            if (empty($user->slug)) {
                $user->slug = static::generateUniqueSlug($user->name);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile_complete' => 'boolean',
            'privacy_settings' => 'array',
            'notification_settings' => 'array',
            'profile_version' => 'integer',
            'profile_updated_at' => 'datetime',
            'password_set_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'is_disabled' => 'boolean',
            'disabled_at' => 'datetime',
            'can_create_public_entries' => 'boolean',
            'preferred_language' => ContentLanguage::class,
            'location' => 'array',
            'reliability_score' => 'array',
            'reliability_computed_at' => 'datetime',
        ];
    }

    // ── Avatar ─────────────────────────────────────────

    /**
     * Resolve avatar URL: media library upload first, then fallback to DB column (OAuth).
     */
    protected function avatarUrl(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (?string $value) {
                $media = $this->getFirstMedia('avatar');
                if ($media) {
                    return $media->getUrl();
                }

                return $value;
            },
        );
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

    public function pushSubscriptions()
    {
        return $this->hasMany(PushSubscription::class);
    }

    // ── Preference Resolution ─────────────────────────

    public function resolvedGameSystemPreferences(): array
    {
        return app(UserPreferenceResolver::class)->resolvedGameSystemPreferences($this);
    }

    public function resolvedVibePreferences(): array
    {
        return app(UserPreferenceResolver::class)->resolvedVibePreferences($this);
    }

    // ── Spatie Media Library ──────────────────────────

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);
    }

    // ── User Relationships ────────────────────────────

    /**
     * Discovery view tracking row (1:1 with User).
     * Used for sweep targeting: last_discovery_view filters active users,
     * geohash_4 enables skip-if-location-unchanged optimization.
     */
    public function gmProfile()
    {
        return $this->hasOne(GMProfile::class);
    }

    public function localSubscriptions()
    {
        return $this->hasMany(LocalSubscription::class);
    }

    /**
     * Check if this user has the Game Master role (subscription-gated).
     */
    public function isGM(): bool
    {
        return $this->hasRole('Game Master');
    }

    /**
     * Check if this user has an active GM subscription (local).
     */
    public function hasGmSubscription(): bool
    {
        return $this->localSubscriptions()
            ->whereHas('membershipType', fn($q) => $q->whereJsonContains('metadata->gm_plan', true))
            ->active()
            ->exists();
    }

    public function discoveryView()
    {
        return $this->hasOne(NearbyDiscoveryView::class);
    }

    /**
     * Users who follow this user (incoming follows).
     */
    public function followers()
    {
        return $this->hasMany(UserRelationship::class, 'related_user_id')
            ->where('type', RelationshipType::Follow);
    }

    /**
     * Users this user follows (outgoing follows).
     */
    public function followings()
    {
        return $this->hasMany(UserRelationship::class, 'user_id')
            ->where('type', RelationshipType::Follow);
    }

    /**
     * Users this user has blocked (outgoing blocks).
     */
    public function blocks()
    {
        return $this->hasMany(UserRelationship::class, 'user_id')
            ->where('type', RelationshipType::Block);
    }

    /**
     * Users who have blocked this user (incoming blocks).
     */
    public function blockedBy()
    {
        return $this->hasMany(UserRelationship::class, 'related_user_id')
            ->where('type', RelationshipType::Block);
    }

    // ── Relationship Resolution ──────────────────────

    public function isFollowing(self $user): bool
    {
        return app(SocialGraphService::class)->isFollowing($this, $user);
    }

    public function isFollowedBy(self $user): bool
    {
        return app(SocialGraphService::class)->isFollowedBy($this, $user);
    }

    public function isFriend(self $user): bool
    {
        return app(SocialGraphService::class)->isFriend($this, $user);
    }

    public function isBlockedBy(self $user): bool
    {
        return app(SocialGraphService::class)->isBlockedBy($this, $user);
    }

    public function hasBlocked(self $user): bool
    {
        return app(SocialGraphService::class)->hasBlocked($this, $user);
    }

    public function getAllowedOwnerIdsForProtectedContent(): array
    {
        return app(SocialGraphService::class)->getAllowedOwnerIdsForProtectedContent($this);
    }

    public function isFriendOrTeammate(self $user): bool
    {
        return app(SocialGraphService::class)->isFriendOrTeammate($this, $user);
    }

    public function getRelationshipLevel(self $user): string
    {
        return app(SocialGraphService::class)->getRelationshipLevel($this, $user);
    }

    // ── Route Model Binding ────────────────────────────

    /**
     * Use slug for route model binding so profiles resolve via /u/{slug}.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve route binding by slug first, then fall back to UUID for backward compatibility.
     * Old /u/{uuid} URLs will still resolve while new URLs use /u/{slug}.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return parent::resolveRouteBinding($value, $field);
        }

        // Try slug first (primary resolution)
        $user = $this->where('slug', $value)->first();

        if ($user) {
            return $user;
        }

        // UUID fallback for backward compatibility with old /u/{uuid} URLs
        if (Str::isUuid($value)) {
            return $this->where('id', $value)->first();
        }

        return null;
    }

    // ── Slug Generation ────────────────────────────────

    /**
     * Generate a URL-friendly slug from a name.
     * Strips emojis and special characters, lowercases, replaces spaces with hyphens.
     * Allows letters, numbers, hyphens, underscores, and dots.
     */
    public static function generateSlug(string $name): string
    {
        // Remove emojis and special characters, keep letters, numbers, spaces
        $slug = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        // Replace spaces with hyphens, collapse multiples
        $slug = preg_replace('/\s+/', '-', trim($slug));
        // Lowercase
        $slug = mb_strtolower($slug);
        // Trim leading/trailing hyphens
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Generate a unique slug for the given name, appending incremental digits on collision.
     */
    public static function generateUniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $baseSlug = static::generateSlug($name);

        if ($baseSlug === '') {
            $baseSlug = 'user';
        }

        $slug = $baseSlug;
        $counter = 1;

        $query = static::where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $counter++;
            $slug = $baseSlug . '-' . $counter;

            $query = static::where('slug', $slug);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $slug;
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Check if this user shares an active team membership with the given user.
     */
    private function hasSharedActiveTeamWith(self $user): bool
    {
        return app(SocialGraphService::class)->hasSharedActiveTeamWith($this, $user);
    }

    public function hasActiveMembership(): bool
    {
        return $this->subscribed() || $this->localSubscriptions()->active()->exists();
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

    // ── SEO ────────────────────────────────────────────

    public function getDynamicSEOData(): SEOData
    {
        $title = $this->name;

        $description = $this->bio
            ? Str::limit(strip_tags($this->bio), 160)
            : "View {$this->name}'s profile on Roundup Games.";

        $image = $this->getFirstMediaUrl('avatar', 'thumb') ?: ($this->avatar_url ?: asset('images/og-default.jpg'));

        // Determine if profile is publicly indexable
        // A profile is indexable if the viewer is a stranger and can still see fields
        $resolver = app(\App\Services\ProfileVisibilityResolver::class);
        $guestVisibleFields = $resolver->profileFieldsVisible(null, $this);
        $robots = count($guestVisibleFields) > 0
            ? 'index, follow'
            : 'noindex, nofollow';

        $schema = null;

        // Only generate Person schema for publicly indexable profiles
        if (str_starts_with($robots, 'index')) {
            $schema = SchemaCollection::initialize();

            $person = (new SchemaPerson)
                ->name($this->name)
                ->url(route('profile.public', $this->slug));

            if ($description) {
                $person->description($description);
            }

            // Avatar image
            $avatarUrl = $this->getFirstMediaUrl('avatar', 'thumb') ?: $this->avatar_url;
            if ($avatarUrl) {
                $person->image($avatarUrl);
            }

            // GM jobTitle
            if ($this->isGM()) {
                $person->jobTitle('Game Master');
            }

            // knowsAbout from game systems they favor/run
            $gameSystemNames = $this->favoriteGameSystems()
                ->pluck('game_systems.name')
                ->unique()
                ->values()
                ->toArray();

            if (! empty($gameSystemNames)) {
                $person->knowsAbout($gameSystemNames);
            }

            $schema->push($person->toArray());
        }

        return new SEOData(
            title: $title,
            description: $description,
            image: $image,
            robots: $robots,
            schema: $schema,
        );
    }
}
