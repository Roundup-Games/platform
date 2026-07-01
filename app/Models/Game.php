<?php

namespace App\Models;

use App\Enums\DisclosureLevel;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Concerns\HasCapacity;
use App\Relations\StringKeyMorphMany;
use App\Services\Geohash;
use App\Services\LocationDisclosureService;
use App\Services\ShortLinkService;
use App\Services\SocialGraphService;
use Database\Factories\GameFactory;
use Escalated\Laravel\Concerns\PresentsAsTicketSubject;
use Escalated\Laravel\Contracts\TicketSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\SchemaOrg\Event as SchemaEvent;
use Spatie\SchemaOrg\EventStatusType;
use Spatie\SchemaOrg\Offer;
use Spatie\SchemaOrg\Person as SchemaPerson;
use Spatie\SchemaOrg\Place;
use Spatie\SchemaOrg\PostalAddress;
use Spatie\Translatable\HasTranslations;

/**
 * @property Carbon|null $date_time
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $reminder_24h_sent_at
 * @property Carbon|null $share_token_expires_at
 * @property Carbon|null $attendance_window_opens_at
 * @property Carbon|null $attendance_window_closes_at
 * @property Carbon|null $attendance_resolved_at
 * @property GameType|null $game_type
 * @property Visibility|null $visibility
 * @property GameStatus|null $status
 * @property bool $bench_mode
 * @property int|null $pending_count
 * @property int|null $approved_count
 * @property string|null $pivot_role
 * @property string|null $pivot_status
 * @property array<string, mixed>|null $location
 * @property array<string, mixed>|null $safety_rules
 * @property array<int, string>|null $game_systems
 * @property string|null $discoverable_type
 * @property int|null $discoverable_sort_key
 * @property float|null $distance_km
 */
class Game extends Model implements TicketSubject
{
    use HasCapacity;

    /** @use HasFactory<GameFactory> */
    use HasFactory;

    use HasSEO;
    use HasTranslations;
    use PresentsAsTicketSubject;

    /**
     * Deep link into the host app for this game when attached as a ticket
     * subject. Uses the public game detail route.
     */
    public function ticketSubjectUrl(): ?string
    {
        return route('games.detail', $this, absolute: false);
    }

    /** @var array<int, string> */
    public array $translatable = ['name', 'description'];

    protected $keyType = 'string';

    public $incrementing = false;

    // bench_mode is GM-gated on create (CreateGame).
    // If an edit/update flow is added later, it MUST also gate bench_mode to GM users.
    // See CreateGame::save() for the reference implementation.
    protected $attributes = [
        'bench_mode' => false,
    ];

    protected $fillable = [
        'owner_id', 'campaign_id', 'game_system_id', 'game_systems', 'name', 'date_time',
        'description', 'host_note', 'expected_duration', 'price', 'language', 'location', 'location_id', 'location_instructions',
        'status', 'game_type', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
        'reminder_sent_at', 'reminder_24h_sent_at', 'recap', 'min_reliability_preference',
        'share_token', 'share_token_expires_at', 'bench_mode',
        'attendance_window_opens_at', 'attendance_window_closes_at',
        'attendance_resolved_at', 'attendance_resolution_method',
    ];

    protected function casts(): array
    {
        return [
            'date_time' => 'datetime',
            'expected_duration' => 'float',
            'price' => 'float',
            'location' => 'array',
            'minimum_requirements' => 'array',
            'safety_rules' => 'array',
            'min_players' => 'integer',
            'max_players' => 'integer',
            'complexity' => 'decimal:2',
            'vibe_flags' => 'array',
            'game_systems' => 'array',
            'game_type' => GameType::class,
            'visibility' => Visibility::class,
            'status' => GameStatus::class,
            'reminder_sent_at' => 'datetime',
            'reminder_24h_sent_at' => 'datetime',
            'min_reliability_preference' => 'decimal:2',
            'share_token' => 'string',
            'share_token_expires_at' => 'datetime',
            'bench_mode' => 'boolean',
            'attendance_window_opens_at' => 'datetime',
            'attendance_window_closes_at' => 'datetime',
            'attendance_resolved_at' => 'datetime',
            'attendance_resolution_method' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $game) {
            if (empty($game->id)) {
                $game->id = (string) Str::uuid();
            }
        });

        // Keep the cached game_system_id anchor synced to the first element of
        // the multi-system game_systems array. The anchor is consumed by ~8
        // read sites (cover image, BGG badge, complexity autofill, schema.org
        // Event), so it must never drift from the array's first element for
        // multi-system games. Legacy single-system games have a null/empty
        // array and keep their game_system_id unchanged.
        static::saving(function (self $game) {
            $systems = $game->game_systems;

            if (! empty($systems)) {
                $expectedAnchor = $systems[0];
                $currentAnchor = $game->game_system_id;

                // Defensive drift detection: warn if the anchor was set to
                // something other than the array's first element. Should never
                // fire in normal operation — if it does, the caller set a
                // mismatched game_system_id that we are now correcting.
                if ($currentAnchor !== null && $currentAnchor !== $expectedAnchor) {
                    Log::warning('game.game_systems.anchor_drift_detected', [
                        'game_id' => $game->id,
                        'old_anchor' => $currentAnchor,
                        'new_anchor' => $expectedAnchor,
                    ]);
                }

                $game->game_system_id = $expectedAnchor;
            }
        });

        static::updated(function (self $game) {
            if ($game->wasChanged('status') && in_array($game->status?->value, ['completed', 'canceled'])) {
                app(ShortLinkService::class)->expireLinksForEntity($game);

                // Expire all active bulletins for this game
                GameBulletin::where('game_id', $game->id)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->update(['expires_at' => now()]);

                Log::info('Game bulletins expired on game completion', [
                    'game_id' => $game->id,
                    'new_status' => $game->status?->value,
                ]);
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
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<GameSystem, $this>
     */
    public function gameSystem(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * @return HasMany<GameParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(GameParticipant::class);
    }

    /** @return HasMany<GameApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(GameApplication::class);
    }

    /**
     * @return HasMany<SessionZeroSurvey, $this>
     */
    public function sessionZeroSurveys(): HasMany
    {
        return $this->hasMany(SessionZeroSurvey::class);
    }

    /** @return HasMany<SessionDebriefing, $this> */
    public function sessionDebriefings(): HasMany
    {
        return $this->hasMany(SessionDebriefing::class);
    }

    /** @return HasMany<AttendanceReport, $this> */
    public function attendanceReports(): HasMany
    {
        return $this->hasMany(AttendanceReport::class);
    }

    /**
     * @return HasMany<GameBulletin, $this>
     */
    public function bulletins(): HasMany
    {
        return $this->hasMany(GameBulletin::class);
    }

    public function activeSessionZeroSurvey(): ?SessionZeroSurvey
    {
        return $this->sessionZeroSurveys()->where('status', 'active')->first();
    }

    // ── Debriefing Helpers ─────────────────────────────

    /**
     * Check if this game has debriefing-capable safety tools selected.
     */
    public function hasDebriefingTools(): bool
    {
        if (! is_array($this->safety_rules)) {
            return false;
        }

        return in_array('debriefing', $this->safety_rules)
            || in_array('stars-and-wishes', $this->safety_rules);
    }

    /**
     * Get the structured debriefing prompts based on selected safety tools.
     *
     * @return array<string, array{prompt: string, confidential?: bool}>
     */
    public function getDebriefingPrompts(): array
    {
        $prompts = [];

        if (! is_array($this->safety_rules)) {
            return $prompts;
        }

        if (in_array('debriefing', $this->safety_rules)) {
            $prompts['what_went_well'] = [
                'prompt' => __('games.content_debriefing_prompt_what_went_well'),
            ];
            $prompts['what_to_change'] = [
                'prompt' => __('games.content_debriefing_prompt_what_to_change'),
            ];
            $prompts['safety_concerns'] = [
                'prompt' => __('games.content_debriefing_prompt_safety_concerns'),
                'confidential' => true,
            ];
        }

        if (in_array('stars-and-wishes', $this->safety_rules)) {
            $prompts['star'] = [
                'prompt' => __('games.content_debriefing_prompt_star'),
            ];
            $prompts['wish'] = [
                'prompt' => __('games.content_debriefing_prompt_wish'),
            ];
        }

        return $prompts;
    }

    /**
     * Determine the primary debriefing tool type for this game.
     * Prefers 'debriefing' over 'stars-and-wishes' when both are present.
     */
    public function getDebriefingToolType(): ?string
    {
        if (! is_array($this->safety_rules)) {
            return null;
        }

        if (in_array('debriefing', $this->safety_rules)) {
            return 'debriefing';
        }

        if (in_array('stars-and-wishes', $this->safety_rules)) {
            return 'stars-and-wishes';
        }

        return null;
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublic(Builder $query)
    {
        return $query->where('visibility', 'public');
    }

    /**
     * Scope to games visible to a given user (or guest).
     *
     * Guests see public only. Authenticated users see public + protected
     * items owned by their connections (friends, teammates) or where they
     * are a participant. Private items are never included in listings.
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeScheduled(Builder $query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('date_time', '>', now())->orderBy('date_time');
    }

    /**
     * Scope to nearby scheduled games with open spots in a geohash tile.
     *
     * The shared scaffold behind the dashboard "nearby noteworthy" and "newcomer
     * matches" computers: locations join + geohash bbox + scheduled status +
     * 14-day window + available-spots SQL filter + participant_count subquery.
     * Callers compose their own exclude-sets, visibility, eager-loads, limit,
     * and scoring on top.
     *
     * @param  Builder<static>  $query
     * @param  string  $geohash4  The geohash tile prefix (4 chars)
     * @return Builder<static>
     */
    public function scopeNearbyOpen(Builder $query, string $geohash4): Builder
    {
        $bounds = Geohash::prefixBounds($geohash4);

        $participantCountSubquery = DB::table('game_participants')
            ->selectRaw('COUNT(*)')
            ->whereColumn('game_participants.game_id', 'games.id')
            ->where('game_participants.status', ParticipantStatus::Approved->value);

        return $query
            ->select('games.*')
            ->selectSub($participantCountSubquery, 'participant_count')
            ->join('locations', 'games.location_id', '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds->minLat, $bounds->maxLat])
            ->whereBetween('locations.longitude', [$bounds->minLng, $bounds->maxLng])
            ->where('games.status', GameStatus::Scheduled->value)
            ->where('games.date_time', '>=', now())
            ->where('games.date_time', '<=', now()->addDays(14))
            ->where(function ($q) {
                $q->whereNull('games.max_players')
                    ->orWhereRaw(
                        '(SELECT COUNT(*) FROM game_participants WHERE game_participants.game_id = games.id AND game_participants.status = ?) < games.max_players',
                        [ParticipantStatus::Approved->value],
                    );
            });
    }

    // ── Bench ──────────────────────────────────────────

    /**
     * Check if this entity uses bench mode (overflow to bench) vs waitlist (FIFO queue).
     *
     * - Standalone games: reads from the bench_mode column.
     * - Campaign sessions: always delegates to the parent campaign's bench_mode.
     *   The game's own bench_mode column is ignored — the campaign owner controls
     *   overflow behavior for all sessions uniformly.
     *
     * IMPORTANT: Callers in listing/card contexts MUST eager-load the 'campaign'
     * relationship (e.g., ->with('campaign')) before calling this method.
     * Without it, campaign sessions fall back to false (waitlist) and log a warning.
     *
     * @see DiscoveryQueryService::buildGamesQuery() — eager-loads 'campaign' for cards
     * @see GameDetail::canApply() / GameDetail::canJoinWaitlist() — single-entity, safe
     */
    public function isBenchMode(): bool
    {
        // Campaign sessions: always delegate to the campaign
        if ($this->campaign_id !== null) {
            $campaign = $this->getRelationValue('campaign');

            if ($campaign === null || ! ($campaign instanceof Campaign)) {
                Log::warning('game.is_bench_mode.campaign_not_loaded', [
                    'game_id' => $this->id,
                    'campaign_id' => $this->campaign_id,
                    'message' => 'Campaign relationship not loaded — defaulting to waitlist mode. Eager-load campaign to avoid this.',
                ]);

                return false;
            }

            return $campaign->isBenchMode();
        }

        // Standalone games: read from own column
        return (bool) $this->bench_mode;
    }

    // ── SEO ────────────────────────────────────────────

    public function getDynamicSEOData(): SEOData
    {
        $description = $this->description
            ? Str::limit(strip_tags($this->description), 160)
            : null;

        $image = $this->gameSystem?->coverImageUrl() ?: asset('images/og-default.jpg');

        $isPublic = $this->visibility === Visibility::Public;
        $robots = $isPublic
            ? 'index, follow'
            : 'noindex, nofollow';

        $schema = null;

        // Only generate Event schema for public-visibility records
        if ($isPublic && $this->date_time) {
            $schema = SchemaCollection::initialize();

            $event = (new SchemaEvent)
                ->name($this->name)
                ->description(Str::limit(strip_tags((string) $this->description), 500) ?: '')
                ->startDate((string) $this->date_time->toISOString())
                ->eventStatus(EventStatusType::EventScheduled)
                ->eventAttendanceMode('OfflineEventAttendanceMode');

            // Cancelled events
            if ($this->status === GameStatus::Canceled) {
                $event->eventStatus(EventStatusType::EventCancelled);
            }

            // End date from expected_duration
            if ($this->expected_duration) {
                $event->endDate((string) $this->date_time->copy()->addHours((float) $this->expected_duration)->toISOString());
            }

            // Location
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
            $event->isAccessibleForFree(empty($this->price));

            // Offers
            if ($this->price && $this->price > 0) {
                $event->offers(
                    (new Offer)
                        ->price($this->price)
                        ->priceCurrency('EUR')
                        ->availability('InStock')
                );
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
     * Build a schema.org Place from the game's location data.
     */
    private function buildEventPlace(): ?Place
    {
        // No normalized location → no Place (fail-closed).
        if (! $this->linkedLocation) {
            return null;
        }

        $location = $this->linkedLocation;

        // JSON-LD is a single server-rendered artifact served to EVERY viewer
        // (including crawlers), so the structured-data location MUST reflect the
        // most-restrictive (stranger) disclosure level — never more. A public
        // game at a private home would otherwise leak the host's street address
        // in the Event schema even though <x-location-display> correctly hides
        // it in the HTML body. Only a verified commercial venue (the Exact rung
        // for a stranger, via the single authority) gets a PostalAddress; every
        // other location emits NO Place so no street/locality reaches an
        // indexed route. Mirrors the Location::getDynamicSEOData is_verified
        // gate and the HTML stranger view (Area rung = "In your area").
        $level = app(LocationDisclosureService::class)->strangerPreviewLevel($location);

        if ($level !== DisclosureLevel::Exact) {
            return null;
        }

        $address = (new PostalAddress);
        if ($location->address) {
            $address->streetAddress($location->address);
        }
        if ($location->city) {
            $address->addressLocality($location->city);
        }
        if ($location->country) {
            $address->addressCountry($location->country);
        }

        return (new Place)
            ->name($location->name)
            ->address($address);
    }
}
