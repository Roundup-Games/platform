<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\GameType;
use App\Enums\Visibility;
use App\Models\Concerns\HasCapacity;
use App\Relations\StringKeyMorphMany;
use App\Services\ShortLinkService;
use App\Services\SocialGraphService;
use App\Traits\ResolvesCoverImage;
use App\Traits\StringMorphMediaKey;
use Database\Factories\CampaignFactory;
use Escalated\Laravel\Concerns\PresentsAsTicketSubject;
use Escalated\Laravel\Contracts\TicketSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\SchemaOrg\Event as SchemaEvent;
use Spatie\SchemaOrg\EventStatusType;
use Spatie\SchemaOrg\Person as SchemaPerson;
use Spatie\SchemaOrg\Place;
use Spatie\SchemaOrg\PostalAddress;
use Spatie\SchemaOrg\Thing;
use Spatie\Translatable\HasTranslations;

/**
 * @property Visibility|null $visibility
 * @property CampaignStatus|null $status
 * @property GameType|null $game_type
 * @property Carbon|null $share_token_expires_at
 * @property bool $bench_mode
 * @property int|null $completed_games_count
 * @property int|null $approved_participant_count
 * @property string|null $pivot_role
 * @property string|null $pivot_status
 * @property string|null $discoverable_type
 * @property int|null $discoverable_sort_key
 * @property int|null $discoverable_gathering_rank
 * @property float|null $distance_km
 */
class Campaign extends Model implements HasMedia, TicketSubject
{
    use HasCapacity;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    // Spatie MediaLibrary: host-uploaded cover images. StringMorphMediaKey
    // overrides media() so the varchar(36) model_id column compares correctly
    // against this model's string PK. Mirrors GameSystem's trait resolution.
    use InteractsWithMedia;
    use PresentsAsTicketSubject;
    use ResolvesCoverImage;
    use StringMorphMediaKey {
        StringMorphMediaKey::media insteadof InteractsWithMedia;
        // Our cover collection/conversion definitions override Spatie's empty
        // HasMedia stubs; class-body precedence is unavailable because we pull
        // them in via a shared trait, so resolve the collisions here.
        ResolvesCoverImage::registerMediaCollections insteadof InteractsWithMedia;
        ResolvesCoverImage::registerMediaConversions insteadof InteractsWithMedia;
    }

    /**
     * Deep link into the host app for this campaign when attached as a
     * ticket subject. Returns null when the campaign has no public route.
     */
    public function ticketSubjectUrl(): ?string
    {
        return route('campaigns.detail', $this, absolute: false);
    }

    use HasSEO;
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'description'];

    protected $keyType = 'string';

    public $incrementing = false;

    // bench_mode is GM-gated on create (CreateGame/CreateCampaign).
    // If an edit/update flow is added later, it MUST also gate bench_mode to GM users.
    // See CreateGame::save() and CreateCampaign::save() for the reference implementation.
    protected $attributes = [
        'bench_mode' => false,
    ];

    /**
     * Write-side bridge for the dropped game_system_id anchor column.
     *
     * @var list<string>
     */
    protected array $pendingGameSystemIds = [];

    protected $fillable = [
        'owner_id', 'game_type', 'location_id', 'location_instructions', 'name', 'description',
        'recurrence', 'time_of_day', 'session_duration', 'price_per_session',
        'language', 'status', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
        'share_token', 'share_token_expires_at', 'bench_mode',
    ];

    protected function casts(): array
    {
        return [
            'session_duration' => 'float',
            'price_per_session' => 'float',
            'minimum_requirements' => 'array',
            'safety_rules' => 'array',
            'min_players' => 'integer',
            'max_players' => 'integer',
            'complexity' => 'decimal:2',
            'vibe_flags' => 'array',
            'visibility' => Visibility::class,
            'status' => CampaignStatus::class,
            'game_type' => GameType::class,
            'share_token' => 'string',
            'share_token_expires_at' => 'datetime',
            'bench_mode' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $campaign) {
            if (empty($campaign->id)) {
                $campaign->id = (string) Str::uuid();
            }
        });

        // Write-side bridge: route any legacy game_system_id mass-assignment
        // to the gameSystems pivot after persist. See Game::booted for details.
        static::created(function (self $campaign) {
            if (! empty($campaign->pendingGameSystemIds)) {
                $campaign->gameSystems()->sync($campaign->pendingGameSystemIds);
                $campaign->load('gameSystems');
            }
        });

        static::updated(function (self $campaign) {
            if ($campaign->wasChanged('status') && in_array($campaign->status?->value, ['completed', 'cancelled'])) {
                app(ShortLinkService::class)->expireLinksForEntity($campaign);
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Every GameSystem offered by this campaign (the recurring default offering).
     *
     * Canonical belongsToMany pivot backed by campaign_game_system (replaces the
     * former cached game_system_id anchor). Eager-load with Campaign::with('gameSystems').
     *
     * @return BelongsToMany<GameSystem, $this>
     */
    public function gameSystems(): BelongsToMany
    {
        return $this->belongsToMany(GameSystem::class, 'campaign_game_system');
    }

    /**
     * Representative GameSystem accessor (bridge for the former BelongsTo anchor).
     *
     * Returns the first offered system, or null when the campaign has none. Kept so
     * ~10 secondary Blade reads ($campaign->gameSystem?->name) and the hero cover-image
     * pick continue to work without per-template migration.
     */
    public function getGameSystemAttribute(): ?GameSystem
    {
        return $this->gameSystems->first();
    }

    /**
     * Representative game_system_id accessor (bridge for the dropped anchor column).
     *
     * Returns the first offered system's id, or null. Kept so property reads
     * ($campaign->game_system_id) across CreateCampaign/AddSessionToCampaign/
     * GenerateUserDataExport continue to work. Callers needing the full set
     * should read ->gameSystems directly.
     */
    public function getGameSystemIdAttribute(): ?string
    {
        $first = $this->gameSystems->first();

        return $first !== null ? (string) $first->id : null;
    }

    /**
     * Write-side bridge for the dropped game_system_id anchor column. See
     * Game::setGameSystemIdAttribute for full rationale.
     */
    public function setGameSystemIdAttribute(mixed $id): void
    {
        if (is_string($id) && $id !== '' && $id !== '0') {
            $this->pendingGameSystemIds[] = $id;
        }
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * @return HasMany<Game, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    /**
     * @return HasMany<CampaignParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(CampaignParticipant::class);
    }

    /** @return HasMany<CampaignApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class);
    }

    // ── Short Links ────────────────────────────────────

    /**
     * @return StringKeyMorphMany<ShortLink, $this>
     */
    public function shortLinks(): StringKeyMorphMany
    {
        $relation = new StringKeyMorphMany(
            $this->newRelatedInstance(ShortLink::class)->newQuery(),
            $this,
            'linkable_type',
            'linkable_id',
            'id'
        );
        $relation->getQuery()->where('linkable_type', static::class);

        return $relation;
    }

    // ── Share Token ────────────────────────────────────

    /**
     * Check whether the current request carries a valid share token for this entity.
     * Validates that: the query param 'share' matches the stored token AND the token hasn't expired.
     */
    public function hasValidShareToken(?string $token = null): bool
    {
        $token = $token ?? request()->query('share');

        if (! $token || ! $this->share_token) {
            return false;
        }

        if ($this->share_token_expires_at !== null && $this->share_token_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->share_token, $token);
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * Scope to campaigns visible to a given user (or guest).
     *
     * Guests see public only. Authenticated users see public + protected
     * items owned by their connections (friends, teammates) or where they
     * are a participant. Private campaigns are never included in listings.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer = null)
    {
        if ($viewer === null) {
            return $query->where('visibility', 'public');
        }

        $allowedOwnerIds = app(SocialGraphService::class)
            ->getAllowedOwnerIdsForProtectedContent($viewer);

        return $query->where(function ($q) use ($allowedOwnerIds, $viewer) {
            $q->where('visibility', 'public')
                ->orWhere(function ($q) use ($allowedOwnerIds, $viewer) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) use ($allowedOwnerIds, $viewer) {
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $viewer->id));
                        });
                });
        });
    }

    // ── Bench ──────────────────────────────────────────

    /**
     * Check if this entity uses bench mode (overflow to bench) vs waitlist (FIFO queue).
     * Reads from the bench_mode column.
     */
    public function isBenchMode(): bool
    {
        return (bool) $this->bench_mode;
    }

    // ── SEO ────────────────────────────────────────────

    public function getDynamicSEOData(): SEOData
    {
        $description = $this->description
            ? Str::limit(strip_tags($this->description), 160)
            : null;

        // Cover image via the deterministic fallback chain (S07): host-uploaded
        // cover -> representative GameSystem cover -> og-default.jpg asset.
        // The legacy $this->images JSON column was dropped in S07; every cover
        // surface now reads through resolveCoverUrl().
        $image = $this->resolveCoverUrl();

        $isPublic = $this->visibility === Visibility::Public;
        $robots = $isPublic
            ? 'index, follow'
            : 'noindex, nofollow';

        $schema = null;

        // Only generate Event schema for public-visibility active campaigns
        if ($isPublic && $this->status === CampaignStatus::Active) {
            $schema = SchemaCollection::initialize();

            $event = (new SchemaEvent)
                ->name($this->name)
                ->description(Str::limit(strip_tags((string) $this->description), 500) ?: '')
                ->eventStatus(EventStatusType::EventScheduled)
                ->eventAttendanceMode('OfflineEventAttendanceMode');

            // Start date: first session date if available
            $firstSession = $this->sessions->sortBy('date_time')->first();
            if ($firstSession && $firstSession->date_time) {
                $event->startDate((string) $firstSession->date_time->toISOString());
                if ($firstSession->expected_duration) {
                    $event->endDate((string) $firstSession->date_time->copy()->addHours((float) $firstSession->expected_duration)->toISOString());
                }
            }

            // Location from linked location
            $place = $this->buildEventPlace();
            if ($place) {
                $event->location($place);
            }

            // Organizer (owner as Person)
            if ($this->owner) {
                $organizer = (new SchemaPerson)
                    ->name($this->owner->name);
                if ($this->owner->username) {
                    $organizer->url(route('profile.public', $this->owner->username));
                }
                $event->organizer($organizer);
            }

            // Maximum attendees
            if ($this->max_players) {
                $event->maximumAttendeeCapacity($this->max_players);
            }

            // Free/paid
            $event->isAccessibleForFree(empty($this->price_per_session));

            // schema.org about: name EVERY offered system so the full
            // recurring offering is honest in structured data. Additive.
            $about = $this->gameSystems->map(function (GameSystem $sys) {
                $thing = (new Thing)->name($sys->name);
                if ($sys->slug) {
                    $thing->identifier($sys->slug);
                }

                return $thing;
            })->values()->all();
            if (! empty($about)) {
                $event->about($about);
            }

            $schema->push($event->toArray());
        }

        return new SEOData(
            title: $this->name,
            description: $description,
            image: $image,
            robots: $robots,
            schema: $schema,
        );
    }

    /**
     * Build a schema.org Place from the campaign's linked location.
     */
    private function buildEventPlace(): ?Place
    {
        if ($this->linkedLocation) {
            $address = (new PostalAddress);
            if ($this->linkedLocation->address) {
                $address->streetAddress($this->linkedLocation->address);
            }
            if ($this->linkedLocation->city) {
                $address->addressLocality($this->linkedLocation->city);
            }
            if ($this->linkedLocation->country) {
                $address->addressCountry($this->linkedLocation->country);
            }

            return (new Place)
                ->name($this->linkedLocation->name)
                ->address($address);
        }

        return null;
    }
}
