<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\Visibility;
use App\Relations\StringKeyMorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\SchemaOrg\Event as SchemaEvent;
use Spatie\SchemaOrg\Place;
use Spatie\SchemaOrg\PostalAddress;
use Spatie\SchemaOrg\Person as SchemaPerson;
use Spatie\SchemaOrg\EventStatusType;
use Spatie\Translatable\HasTranslations;

class Campaign extends Model
{
    use HasFactory;
    use HasSEO;
    use HasTranslations;

    public array $translatable = ['name', 'description'];
    protected $keyType = 'string';
    public $incrementing = false;

    // bench_mode is GM-gated on create (CreateGame/CreateCampaign).
    // If an edit/update flow is added later, it MUST also gate bench_mode to GM users.
    // See CreateGame::save() and CreateCampaign::save() for the reference implementation.
    protected $attributes = [
        'bench_mode' => false,
    ];

    protected $fillable = [
        'owner_id', 'game_system_id', 'location_id', 'name', 'description', 'images',
        'recurrence', 'time_of_day', 'session_duration', 'price_per_session',
        'language', 'status', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
        'share_token', 'share_token_expires_at', 'bench_mode',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
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

        static::updated(function (self $campaign) {
            if ($campaign->wasChanged('status') && in_array($campaign->status->value, ['completed', 'cancelled'])) {
                app(\App\Services\ShortLinkService::class)->expireLinksForEntity($campaign);
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function gameSystem(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class);
    }

    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CampaignParticipant::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class);
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

    /**
     * Scope to campaigns visible to a given user (or guest).
     *
     * Guests see public only. Authenticated users see public + protected
     * items owned by their connections (friends, teammates) or where they
     * are a participant. Private campaigns are never included in listings.
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

        $image = isset($this->images[0]) && $this->images[0]
            ? $this->images[0]
            : ($this->gameSystem?->coverImageUrl() ?: asset('images/og-default.jpg'));

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
                ->description(Str::limit(strip_tags($this->description ?? ''), 500) ?: null)
                ->eventStatus(EventStatusType::EventScheduled)
                ->eventAttendanceMode('OfflineEventAttendanceMode');

            // Start date: first session date if available
            $firstSession = $this->sessions->sortBy('date_time')->first();
            if ($firstSession && $firstSession->date_time) {
                $event->startDate($firstSession->date_time->toISOString());
                if ($firstSession->expected_duration) {
                    $event->endDate($firstSession->date_time->copy()->addHours((float) $firstSession->expected_duration)->toISOString());
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
                $event->maximumAttendees($this->max_players);
            }

            // Free/paid
            $event->isAccessibleForFree(empty($this->price_per_session));

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
