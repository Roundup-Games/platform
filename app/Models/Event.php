<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Relations\StringKeyMorphMany;
use App\Services\ShortLinkService;
use App\Traits\StringMorphMediaKey;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\SchemaOrg\Event as SchemaEvent;
use Spatie\SchemaOrg\EventStatusType;
use Spatie\SchemaOrg\Offer;
use Spatie\SchemaOrg\Person as SchemaPerson;
use Spatie\SchemaOrg\Place;
use Spatie\SchemaOrg\PostalAddress;
use Spatie\SchemaOrg\Thing;
use Spatie\Translatable\HasTranslations;

/**
 * @property Carbon|null $start_date
 * @property string $hub_item_type
 * @property Carbon $hub_sort_at
 * @property Carbon|null $end_date
 * @property Carbon|null $registration_opens_at
 * @property Carbon|null $registration_closes_at
 * @property Carbon|null $early_bird_deadline
 * @property EventStatus|null $status
 * @property EventType|null $type
 * @property string|null $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $individual_registration_fee
 * @property array{paddle_price_id?: string}|null $metadata
 * @property int|null $tables_count
 */
class Event extends Model implements HasMedia
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasSEO;
    use HasTranslations;
    use InteractsWithMedia;
    use StringMorphMediaKey { StringMorphMediaKey::media insteadof InteractsWithMedia; }

    /** @var array<int, string> */
    public array $translatable = ['name', 'description', 'short_description'];

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Valid status transitions for the event state machine.
     * Each key maps to an array of statuses that may follow it.
     */
    public const VALID_TRANSITIONS = [
        'draft' => ['published'],
        'published' => ['registration_open', 'cancelled'],
        'registration_open' => ['registration_closed', 'cancelled'],
        'registration_closed' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => ['draft'],
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'short_description', 'type', 'status', 'language',
        'venue_name', 'venue_address', 'city', 'country', 'postal_code', 'location_id',
        'start_date', 'end_date', 'registration_opens_at', 'registration_closes_at',
        'max_participants',
        'individual_registration_fee',
        'early_bird_discount', 'early_bird_deadline',
        'organizer_id', 'contact_email', 'contact_phone',
        'rules', 'schedule', 'amenities', 'requirements',
        'is_public', 'is_featured', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'early_bird_deadline' => 'datetime',
            'individual_registration_fee' => 'integer',
            'early_bird_discount' => 'integer',
            'max_participants' => 'integer',
            'rules' => 'array',
            'schedule' => 'array',
            'amenities' => 'array',
            'requirements' => 'array',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'metadata' => 'array',
            'status' => EventStatus::class,
            'type' => EventType::class,
        ];
    }

    // ── Paddle Ticketing ──────────────────────────────

    /**
     * Typed accessor/mutator for the Paddle one-time price id backing the
     * individual registration fee, persisted as metadata.paddle_price_id.
     *
     * Reading: $event->paddle_price_id (null when unset).
     * Writing: merges into metadata without clobbering other keys; assigning
     * null (or an empty string) removes the key entirely instead of leaving
     * a null entry behind.
     *
     * The set closure must JSON-encode the merged array itself: Laravel
     * writes multi-attribute mutator return values into $this->attributes
     * verbatim, so the 'array' cast on metadata never runs on this path.
     * An emptied metadata map maps back to the column's null state.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function paddlePriceId(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->metadata ?? [])['paddle_price_id'] ?? null,
            set: function (?string $value): array {
                $metadata = $this->metadata ?? [];
                $trimmed = trim((string) $value);

                if ($trimmed !== '') {
                    $metadata['paddle_price_id'] = $trimmed;
                } else {
                    unset($metadata['paddle_price_id']);
                }

                return ['metadata' => $metadata === [] ? null : $this->asJson($metadata)];
            },
        );
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            if (empty($event->id)) {
                $event->id = (string) Str::uuid();
            }
            if (empty($event->slug)) {
                // name is a spatie JSON column — getTranslation extracts the locale key.
                // Falls back to the raw attribute if the value isn't JSON yet (spatie handles this).
                $locale = $event->language ?? 'en';
                $name = $event->getTranslation('name', $locale);
                $event->slug = Str::slug(is_string($name) ? $name : '').'-'.Str::random(6);
            }
        });

        static::updated(function (self $event) {
            if ($event->wasChanged('status') && in_array($event->status?->value, ['completed', 'cancelled'])) {
                app(ShortLinkService::class)->expireLinksForEntity($event);
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

        $this->addMediaCollection('banner')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);

        $this->addMediaConversion('medium')
            ->width(400)
            ->height(400)
            ->sharpen(8);

        $this->addMediaConversion('large')
            ->width(1200)
            ->height(630)
            ->sharpen(5);

        $this->addMediaConversion('banner_thumb')
            ->width(400)
            ->height(210)
            ->sharpen(8);
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * @return HasMany<EventRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /**
     * @return HasMany<EventAnnouncement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(EventAnnouncement::class);
    }

    /**
     * The tables (Games) hosted at this event (R059).
     *
     * A "table" is a regular Game row linked via games.event_id — a Gathering
     * is the expected shape, but no game_type restriction is enforced. Ordered
     * by start time (date_time) with created_at as the deterministic tiebreaker
     * for tables starting together. Games::event() is the inverse belongsTo.
     *
     * The link is lifecycle-neutral: event cancel/complete leaves tables
     * untouched, and deleting the event only detaches them (FK nullOnDelete).
     *
     * @return HasMany<Game, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Game::class)
            ->orderBy('games.date_time')
            ->orderBy('games.created_at');
    }

    /**
     * Number of tables hosted at this event.
     *
     * Reads the withCount('tables') aggregate when present so card grids can
     * eager-load all counts in one query; falls back to a count query when
     * the aggregate was not selected.
     */
    public function tablesCount(): int
    {
        $count = $this->attributes['tables_count'] ?? null;

        return is_numeric($count) ? (int) $count : (int) $this->tables()->count();
    }

    // ── Derived Offering (M063/S06/T02) ────────────────

    /**
     * Cache key for the derived offering, namespaced per event.
     */
    public static function offeredSystemsCacheKey(string $eventId): string
    {
        return "event:{$eventId}:offered-systems";
    }

    /**
     * Flush this event's derived-offering cache (static form — callers that
     * only hold an event id, e.g. GameObserver on a detach, use this).
     */
    public static function flushOfferedSystemsCacheFor(string $eventId): void
    {
        Cache::forget(static::offeredSystemsCacheKey($eventId));
    }

    /**
     * Flush this event's derived-offering cache (instance form).
     */
    public function flushOfferedSystemsCache(): void
    {
        static::flushOfferedSystemsCacheFor($this->id);
    }

    /**
     * Every GameSystem offered across this event's tables — the umbrella's
     * honest full offering as a derived union of the per-table gameSystems
     * pivots (R051 applied across the whole get-together, mirroring
     * Campaign::gameSystems / Game::gameSystems).
     *
     * Per-table honesty is untouched: each table keeps its own pivot; this
     * unions and dedupes them. Reads are zero-query when the caller already
     * eager-loaded tables.gameSystems (the event detail page does); cold
     * reads hit a per-event cache (TTL matching the discovery cache
     * convention) so card grids don't re-aggregate per request. GameObserver
     * flushes the key when a hosted game is saved/deleted (covers table
     * attach/detach via the original event id) and the host-a-table sync
     * site flushes on system attach/detach.
     *
     * The cached payload is the deduped system ID list, NOT model objects:
     * Eloquent payloads don't survive real cache-store serialization
     * round-trips (the dev stack's redis store serves __PHP_Incomplete_Class
     * reads where the array test driver kept passing). A warm read therefore
     * costs one bounded primary-key hydration query instead of the two-query
     * aggregation — the eager-loaded zero-query path is unaffected.
     *
     * @return Collection<int, GameSystem>
     */
    public function offeredSystems(): Collection
    {
        $cacheKey = static::offeredSystemsCacheKey($this->id);
        $configuredTtl = config('discovery.cache_ttl', 900);
        $ttl = now()->addSeconds(is_numeric($configuredTtl) ? (int) $configuredTtl : 900);

        // Zero-query path: tables and their systems already in memory
        // (EventDetail::render eager-loads exactly this shape). The union
        // is written through to the per-event cache (as scalar ids) because
        // other consumers of this request hydrate a FRESH copy of the event
        // (the SEO row's morphTo inverse in seo()->for()) and re-read the
        // offering — a warm key keeps those down to one PK hydration.
        if ($this->relationLoaded('tables')) {
            $tables = $this->tables;

            if ($tables->every(fn (Game $table): bool => $table->relationLoaded('gameSystems'))) {
                $union = $this->unionOfferedSystems($tables);

                Cache::put($cacheKey, $union->pluck('id')->all(), $ttl);

                return $union;
            }
        }

        /** @var array<int, string> $ids */
        $ids = Cache::remember(
            $cacheKey,
            $ttl,
            fn (): array => $this->unionOfferedSystems(
                $this->tables()->with('gameSystems')->get()
            )->pluck('id')->all(),
        );

        if ($ids === []) {
            return collect();
        }

        // Rehydrate in cached order so chip grids render deterministically.
        $position = array_flip($ids);

        return GameSystem::query()
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (GameSystem $system): int => $position[$system->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Union + dedupe the offered systems across a set of tables.
     *
     * @param  Collection<int, Game>  $tables
     * @return Collection<int, GameSystem>
     */
    private function unionOfferedSystems(Collection $tables): Collection
    {
        return $tables
            ->flatMap(fn (Game $table): Collection => $table->gameSystems)
            ->unique('id')
            ->values();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
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

    // ── Scopes ─────────────────────────────────────────

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublic(Builder $query)
    {
        return $query->where('is_public', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFeatured(Builder $query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRegistrationOpen(Builder $query)
    {
        return $query->where('status', EventStatus::RegistrationOpen);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('start_date', '>=', now())->orderBy('start_date');
    }

    // ── State Machine ──────────────────────────────────

    /**
     * Check whether a transition from one status to another is valid.
     */
    public static function isValidStatusTransition(string $from, string $to): bool
    {
        return in_array($to, self::VALID_TRANSITIONS[$from] ?? [], true);
    }

    // ── Helpers ────────────────────────────────────────

    public function isRegistrationOpen(): bool
    {
        if ($this->status !== EventStatus::RegistrationOpen) {
            return false;
        }

        if ($this->registration_opens_at && now()->lt($this->registration_opens_at)) {
            return false;
        }

        if ($this->registration_closes_at && now()->gt($this->registration_closes_at)) {
            return false;
        }

        return true;
    }

    public function hasCapacity(): bool
    {
        if ($this->max_participants && $this->registrations()->count() >= $this->max_participants) {
            return false;
        }

        return true;
    }

    /**
     * May new tables (Games) be hosted at this event? (M063/S05)
     *
     * Only while the event is published or open for registration — the
     * two statuses where the umbrella is visible and taking sign-ups.
     * registration_closed/cancelled/completed never accept new tables,
     * and drafts are not public yet. This gates the ManageEvent Tables
     * tab CTA and the CreateGame save-time attach check. Existing links
     * are lifecycle-neutral: they survive cancel/complete untouched and
     * only event deletion detaches them (FK nullOnDelete, R059).
     */
    public function canHostTables(): bool
    {
        return in_array($this->status, [EventStatus::Published, EventStatus::RegistrationOpen], true);
    }

    // ── SEO ────────────────────────────────────────────

    public function getDynamicSEOData(): SEOData
    {
        $shortDescription = $this->short_description;
        $description = (! blank($shortDescription))
            ? $shortDescription
            : (filled($this->description) ? Str::limit(strip_tags($this->description), 160) : null);

        $image = $this->getFirstMediaUrl('banner', 'large') ?: asset('images/og-default.jpg');

        $isPublic = $this->is_public && in_array($this->status, [
            EventStatus::Published,
            EventStatus::RegistrationOpen,
            EventStatus::RegistrationClosed,
            EventStatus::InProgress,
        ]);
        $robots = $isPublic
            ? 'index, follow'
            : 'noindex, nofollow';

        $schema = null;

        // Only generate Event schema for publicly visible events
        if ($isPublic) {
            $schema = SchemaCollection::initialize();

            $event = (new SchemaEvent)
                ->name($this->name)
                ->description(filled($this->description) ? Str::limit(strip_tags((string) $this->description), 500) : '')
                ->eventStatus(EventStatusType::EventScheduled)
                ->eventAttendanceMode('OfflineEventAttendanceMode');

            // Start/end dates
            if ($this->start_date) {
                $event->startDate($this->start_date->toDateString());
            }
            if ($this->end_date) {
                $event->endDate($this->end_date->toDateString());
            }

            // Cancelled events — use readAttribute to avoid PHPStan type narrowing from the isPublic check
            $status = $this->getAttribute('status');
            if ($status === EventStatus::Cancelled) {
                $event->eventStatus(EventStatusType::EventCancelled);
            }

            // Location
            $place = $this->buildEventPlace();
            if ($place) {
                $event->location($place);
            }

            // Organizer
            if ($this->organizer) {
                $organizer = (new SchemaPerson)
                    ->name($this->organizer->name);
                // The public profile route binds by slug (User::getRouteKeyName()).
                // This previously read ->username — a column that does not exist,
                // so the organizer URL was silently never emitted; strict model
                // mode surfaced the phantom attribute read.
                if ($this->organizer->slug) {
                    $organizer->url(route('profile.public', $this->organizer));
                }
                $event->organizer($organizer);
            }

            // Attendance capacity
            if ($this->max_participants) {
                $event->maximumAttendeeCapacity($this->max_participants);
            }

            // Offers — individual registration fee
            $fee = $this->individual_registration_fee;
            $event->isAccessibleForFree(empty($fee));

            if ($fee > 0) {
                $event->offers(
                    (new Offer)
                        ->price($fee)
                        ->priceCurrency('EUR')
                        ->availability('InStock')
                );
            }

            // schema.org about: name EVERY system offered across the event's
            // tables (the derived offeredSystems union) so the umbrella's
            // structured data is as honest as its tables map — mirrors
            // Game::getDynamicSEOData. Spatie\SchemaOrg\Type::__call stores
            // arbitrary properties, so about() is safe even when not
            // auto-generated. Additive enrichment; a tableless event emits
            // no about().
            $about = $this->offeredSystems()->map(function (GameSystem $system) {
                $thing = (new Thing)->name($system->name);
                if ($system->slug) {
                    $thing->identifier($system->slug);
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
     * Build a schema.org Place from the event's location data.
     */
    private function buildEventPlace(): ?Place
    {
        // Prefer linked location relationship
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

        // Fallback to venue fields on the event itself
        if ($this->venue_name || $this->city) {
            $address = new PostalAddress;
            if ($this->venue_address) {
                $address->streetAddress($this->venue_address);
            }
            if ($this->city) {
                $address->addressLocality($this->city);
            }
            if ($this->country) {
                $address->addressCountry($this->country);
            }

            return (new Place)
                ->name($this->venue_name ?: $this->city)
                ->address($address);
        }

        return null;
    }
}
