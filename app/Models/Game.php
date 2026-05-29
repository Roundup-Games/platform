<?php

namespace App\Models;

use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\Visibility;
use App\Relations\StringKeyMorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\SchemaOrg\Event as SchemaEvent;
use Spatie\SchemaOrg\Place;
use Spatie\SchemaOrg\PostalAddress;
use Spatie\SchemaOrg\Offer;
use Spatie\SchemaOrg\Person as SchemaPerson;
use Spatie\SchemaOrg\EventStatusType;
use Spatie\Translatable\HasTranslations;

class Game extends Model
{
    use HasFactory;
    use HasSEO;
    use HasTranslations;

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
        'owner_id', 'campaign_id', 'game_system_id', 'name', 'date_time',
        'description', 'expected_duration', 'price', 'language', 'location', 'location_id',
        'status', 'game_type', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
        'reminder_sent_at', 'reminder_24h_sent_at', 'recap', 'min_reliability_preference',
        'share_token', 'share_token_expires_at', 'bench_mode',
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
            'game_type' => GameType::class,
            'visibility' => Visibility::class,
            'status' => GameStatus::class,
            'reminder_sent_at' => 'datetime',
            'reminder_24h_sent_at' => 'datetime',
            'min_reliability_preference' => 'decimal:2',
            'share_token' => 'string',
            'share_token_expires_at' => 'datetime',
            'bench_mode' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $game) {
            if (empty($game->id)) {
                $game->id = (string) Str::uuid();
            }
        });

        static::updated(function (self $game) {
            if ($game->wasChanged('status') && in_array($game->status->value, ['completed', 'canceled'])) {
                app(\App\Services\ShortLinkService::class)->expireLinksForEntity($game);

                // Expire all active bulletins for this game
                \App\Models\GameBulletin::where('game_id', $game->id)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->update(['expires_at' => now()]);

                // Invalidate action center cache for all approved participants
                $participantIds = $game->participants()
                    ->where('status', \App\Enums\ParticipantStatus::Approved->value)
                    ->pluck('user_id');

                $cacheService = app(\App\Services\DashboardCacheService::class);
                foreach ($participantIds as $userId) {
                    $cacheService->invalidateForUser((string) $userId, ['action_center']);
                }

                Log::info('Game bulletins expired on game completion', [
                    'game_id' => $game->id,
                    'new_status' => $game->status->value,
                    'participants_invalidated' => $participantIds->count(),
                ]);
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function gameSystem(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class);
    }

    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(GameParticipant::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(GameApplication::class);
    }

    public function sessionZeroSurveys(): HasMany
    {
        return $this->hasMany(SessionZeroSurvey::class);
    }

    public function sessionDebriefings(): HasMany
    {
        return $this->hasMany(SessionDebriefing::class);
    }

    public function bulletins(): HasMany
    {
        return $this->hasMany(GameBulletin::class);
    }

    public function activeSessionZeroSurvey(): ?SessionZeroSurvey
    {
        return $this->sessionZeroSurveys()->active()->first();
    }

    // ── Debriefing Helpers ─────────────────────────────

    /**
     * Check if this game has debriefing-capable safety tools selected.
     */
    public function hasDebriefingTools(): bool
    {
        if (! $this->safety_rules || ! is_array($this->safety_rules)) {
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

        if (! $this->safety_rules || ! is_array($this->safety_rules)) {
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
        if (! $this->safety_rules || ! is_array($this->safety_rules)) {
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

    public function shortLinks()
    {
        return (new StringKeyMorphMany(
            $this->newRelatedInstance(ShortLink::class)->newQuery(),
            $this,
            'linkable_type',
            'linkable_id',
            'id'
        ))->where('linkable_type', static::class);
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

    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    /**
     * Scope to games visible to a given user (or guest).
     *
     * Guests see public only. Authenticated users see public + protected
     * items owned by their connections (friends, teammates) or where they
     * are a participant. Private items are never included in listings.
     */
    public function scopeVisibleTo($query, ?User $viewer = null)
    {
        if ($viewer === null) {
            return $query->where('visibility', 'public');
        }

        $allowedOwnerIds = app(\App\Services\SocialGraphService::class)
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

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('date_time', '>', now())->orderBy('date_time');
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

            if ($campaign === null) {
                \Illuminate\Support\Facades\Log::warning('game.is_bench_mode.campaign_not_loaded', [
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
                ->description(Str::limit(strip_tags($this->description ?? ''), 500) ?: null)
                ->startDate($this->date_time->toISOString())
                ->eventStatus(EventStatusType::EventScheduled)
                ->eventAttendanceMode('OfflineEventAttendanceMode');

            // Cancelled events
            if ($this->status === GameStatus::Canceled) {
                $event->eventStatus(EventStatusType::EventCancelled);
            }

            // End date from expected_duration
            if ($this->expected_duration) {
                $event->endDate($this->date_time->copy()->addHours((float) $this->expected_duration)->toISOString());
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
                $event->maximumAttendees($this->max_players);
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
        // Prefer linked location relationship (has city, address, etc.)
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

        // Fallback to location JSON array
        if ($this->location && ! empty($this->location['details'])) {
            return (new Place)
                ->name($this->location['details']);
        }

        return null;
    }
}
