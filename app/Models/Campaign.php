<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\DisclosureLevel;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\JournalEntryKind;
use App\Enums\Visibility;
use App\Models\Concerns\HasCapacity;
use App\Models\Concerns\HasEntitySeo;
use App\Models\Concerns\HasPlatformUuid;
use App\Models\Concerns\HasShareToken;
use App\Models\Concerns\VisibleToScope;
use App\Services\LocationDisclosureService;
use App\Services\ShortLinkService;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
 * @property Carbon|null $created_at
 * @property string $hub_item_type
 * @property Carbon $hub_sort_at
 * @property bool $bench_mode
 * @property string|null $recruitment_summary
 * @property int|null $recruitment_summary_completed_sessions
 * @property Carbon|null $recruitment_summary_refreshed_at
 * @property int|null $completed_games_count
 * @property int|null $completed_sessions_count
 * @property int|null $covered_sessions_count
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

    use HasPlatformUuid;
    use HasShareToken;

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

    /** @use VisibleToScope<static> */
    use VisibleToScope;

    /**
     * Deep link into the host app for this campaign when attached as a
     * ticket subject. Returns null when the campaign has no public route.
     */
    public function ticketSubjectUrl(): ?string
    {
        return route('campaigns.detail', $this, absolute: false);
    }

    use HasEntitySeo;
    use HasSEO;
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'description', 'seo_title', 'seo_description'];

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
        'host_note',
        'recurrence', 'time_of_day', 'session_duration', 'price_per_session',
        'language', 'status', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
        'share_token', 'share_token_expires_at', 'bench_mode',
        'recruitment_summary', 'recruitment_summary_completed_sessions',
        'recruitment_summary_refreshed_at',
        // Bridge: the dropped game_system_id column is captured by
        // setGameSystemIdAttribute and synced to the gameSystems pivot.
        'game_system_id',
        'seo_title', 'seo_description',
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
            'recruitment_summary_completed_sessions' => 'integer',
            'recruitment_summary_refreshed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Write-side bridge: route any legacy game_system_id mass-assignment
        // to the gameSystems pivot after persist. See Game::booted for details.
        static::created(function (self $campaign) {
            if ($campaign->pendingGameSystemIds !== []) {
                $campaign->gameSystems()->sync($campaign->pendingGameSystemIds);
                $campaign->load('gameSystems');
            }
        });

        static::updated(function (self $campaign) {
            if ($campaign->wasChanged('status') && in_array($campaign->status?->value, ['completed', 'cancelled'], true)) {
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
        // orderBy slug so the "representative first system" accessors are
        // deterministic across queries (see Game::gameSystems for rationale).
        //
        // NOTE: order by `slug` (a real varchar column), NOT `name` — name is a
        // JSONB translatable column, and PostgreSQL rejects `ORDER BY name` under
        // `SELECT DISTINCT` (json/jsonb equality-operator/DISTINCT constraints).
        return $this->belongsToMany(GameSystem::class, 'campaign_game_system')
            ->orderBy('game_systems.slug');
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

        return $first !== null ? $first->id : null;
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

    /**
     * @return MorphMany<Review, $this>
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /** @return HasMany<CampaignApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class);
    }

    /**
     * The campaign's charter (M070): a session_zero_surveys row with
     * kind='charter'. At most one per campaign — enforced at the database
     * level by the partial unique index
     * session_zero_surveys_charter_campaign_unique (race backstop).
     *
     * hasOne + where: charters live in the shared session_zero_surveys
     * table, so the kind predicate is part of the relation itself — every
     *
     * @return HasOne<SessionZeroSurvey, $this>
     */
    public function charter(): HasOne
    {
        return $this->hasOne(SessionZeroSurvey::class);
    }

    /**
     * The campaign's journal entries (M071, docs/spec/campaign-journal-concept.md
     * §2): recaps, in-character posts and GM prep notes, ordered oldest-first
     * through Eloquent's default. The journal keys off campaign_id only — every
     * campaign type gets one (D218).
     *
     * @return HasMany<CampaignJournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(CampaignJournalEntry::class);
    }

    /**
     * The campaign's goals (M071, docs/spec/campaign-journal-concept.md
     * §2): system-agnostic board cards for the Journal tab's goal board,
     * grouped by status and ordered by sort at read time.
     *
     * @return HasMany<CampaignGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(CampaignGoal::class);
    }

    /**
     * The campaign's Previously On drafts (M071/S04,
     * docs/spec/campaign-journal-concept.md §3, D221): one per target
     * session (unique game_id at the database level), each awaiting or
     * recording the member GM's one-click publish.
     *
     * @return HasMany<PreviouslyOnDraft, $this>
     */
    public function previouslyOnDrafts(): HasMany
    {
        return $this->hasMany(PreviouslyOnDraft::class);
    }

    /**
     * The campaign's character state changes (M071,
     * docs/spec/campaign-journal-concept.md §2): the rows behind the
     * per-character state timelines and the S04 Previously On scans
     * ((campaign_id, created_at) index).
     *
     * @return HasMany<CharacterStateChange, $this>
     */
    public function stateChanges(): HasMany
    {
        return $this->hasMany(CharacterStateChange::class);
    }

    // ── Recruitment summary (M071/S05) ────────────────

    /**
     * Completed campaign sessions (D222): every Game linked via
     * campaign_id with status completed — session zero included, since a
     * completed Game linked via campaign_id is a real table sitting.
     *
     * Same semantics as CampaignCompleatEvaluator's
     * completed_sessions_count aggregate; the cross-agreement is pinned
     * by RecruitmentSummaryServiceTest. A helper (not a withCount
     * alias) because the composer and the staleness nudge read it on a
     * hydrated model, while the evaluator keeps its own queries per the
     * badge-engine convention.
     */
    public function completedSessionsCount(): int
    {
        return $this->sessions()
            ->where('games.status', GameStatus::Completed->value)
            ->count();
    }

    /**
     * Sessions covered by a journal recap (D222): distinct game_id count
     * over kind=recap journal entries whose linked Game is completed.
     * Interlude recaps (game_id null) and non-recap entries never count.
     *
     * Same semantics as CampaignCompleatEvaluator's
     * covered_sessions_count aggregate; pinned alongside it by
     * RecruitmentSummaryServiceTest.
     */
    public function recapCoveredSessionsCount(): int
    {
        return $this->journalEntries()
            ->where('campaign_journal_entries.kind', JournalEntryKind::Recap->value)
            ->whereNotNull('campaign_journal_entries.game_id')
            ->whereHas('game', fn (Builder $game): Builder => $game
                ->where('games.status', GameStatus::Completed->value))
            ->distinct()
            ->count('campaign_journal_entries.game_id');
    }

    /**
     * Completed sessions accrued since the recruitment summary was last
     * stamped (spec §4.1 staleness): 0 when no summary is published,
     * else max(0, completedSessionsCount() - stamp). The staleness nudge
     * fires at >= 3 (CampaignRecruitmentSummaryService::isStale).
     */
    public function recruitmentSummaryStaleSessions(): int
    {
        if ($this->recruitment_summary === null) {
            return 0;
        }

        $stamped = is_numeric($this->recruitment_summary_completed_sessions)
            ? $this->recruitment_summary_completed_sessions
            : 0;

        return max(0, $this->completedSessionsCount() - $stamped);
    }

    // ── Bench ──────────────────────────────────────────

    /**
     * Check if this entity uses bench mode (overflow to bench) vs waitlist (FIFO queue).
     * Reads from the bench_mode column.
     */
    public function isBenchMode(): bool
    {
        return $this->bench_mode;
    }

    // ── SEO ────────────────────────────────────────────

    public function getDynamicSEOData(): SEOData
    {
        $description = $this->description !== ''
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
                ->description(Str::limit(strip_tags($this->description), 500))
                ->eventStatus(EventStatusType::EventScheduled)
                ->eventAttendanceMode('OfflineEventAttendanceMode');

            // Start date: first session date if available
            $firstSession = $this->sessions->sortBy('date_time')->first();
            if ($firstSession !== null && $firstSession->date_time !== null) {
                $event->startDate((string) $firstSession->date_time->toISOString());
                if ($firstSession->expected_duration > 0) {
                    $event->endDate((string) $firstSession->date_time->copy()->addHours($firstSession->expected_duration)->toISOString());
                }
            }

            // Location from linked location
            $place = $this->buildEventPlace();
            if ($place !== null) {
                $event->location($place);
            }

            // Organizer (owner as Person)
            if ($this->owner !== null) {
                $organizer = (new SchemaPerson)
                    ->name($this->owner->name);
                // The public profile route binds by slug (User::getRouteKeyName()).
                // This previously read ->username — a column that does not exist,
                // so the organizer URL was silently never emitted; strict model
                // mode surfaced the phantom attribute read.
                if ($this->owner->slug !== null && $this->owner->slug !== '') {
                    $organizer->url(route('profile.public', $this->owner));
                }
                $event->organizer($organizer);
            }

            // Maximum attendees
            if ($this->max_players > 0) {
                $event->maximumAttendeeCapacity($this->max_players);
            }

            // Free/paid
            $event->isAccessibleForFree($this->price_per_session === null || $this->price_per_session === 0.0);

            // schema.org about: name EVERY offered system so the full
            // recurring offering is honest in structured data. Additive.
            $about = $this->gameSystems->map(function (GameSystem $sys) {
                $thing = (new Thing)->name($sys->name);
                if ($sys->slug !== null && $sys->slug !== '') {
                    $thing->identifier($sys->slug);
                }

                return $thing;
            })->values()->all();
            if ($about !== []) {
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
     *
     * JSON-LD is a single server-rendered artifact served to EVERY viewer
     * (including crawlers), so the structured-data location MUST reflect the
     * most-restrictive (stranger) disclosure level — never more. A public
     * campaign at a private home would otherwise leak the host's street
     * address in the Event schema even though <x-location-display> correctly
     * hides it in the HTML body. Only a verified commercial venue (the Exact
     * rung for a stranger, via the single authority) gets a PostalAddress;
     * every other location emits NO Place so no street/locality reaches an
     * indexed route. Mirrors Game::buildEventPlace() (M053 regression).
     */
    private function buildEventPlace(): ?Place
    {
        // No normalized location → no Place (fail-closed).
        if ($this->linkedLocation === null) {
            return null;
        }

        $location = $this->linkedLocation;

        $level = app(LocationDisclosureService::class)->strangerPreviewLevel($location);

        if ($level !== DisclosureLevel::Exact) {
            return null;
        }

        $address = (new PostalAddress);
        if ($location->address !== null && $location->address !== '') {
            $address->streetAddress($location->address);
        }
        if ($location->city !== null && $location->city !== '') {
            $address->addressLocality($location->city);
        }
        if ($location->country !== null && $location->country !== '') {
            $address->addressCountry($location->country);
        }

        return (new Place)
            ->name($location->name)
            ->address($address);
    }

    /**
     * Build a flat [id => label] option map for Filament selects.
     *
     * campaigns.name is a JSONB Spatie-translatable column and campaigns has
     * `json`-typed columns (safety_rules, vibe_flags), so Filament's default
     * relationship-option query would either display raw JSON or throw on
     * `SELECT DISTINCT *`. This selects only `id` + `name->>'en'`.
     *
     * @return array<string, string>
     */
    public static function labelOptions(?string $search = null, int $limit = 50): array
    {
        $rows = static::query()
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->whereRaw("name->>'en' ILIKE ?", ["%{$search}%"]))
            ->orderByRaw("name->>'en'")
            ->limit($limit)
            ->select('id')
            ->selectRaw("name->>'en' AS label")
            ->toBase()  // stdClass rows, not hydrated Models
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $id = isset($row->id) ? (string) $row->id : '';
            $rawLabel = $row->label ?? null;
            $label = is_string($rawLabel) && $rawLabel !== '' ? $rawLabel : $id;
            if ($id !== '') {
                $options[$id] = $label;
            }
        }

        return $options;
    }

    /**
     * Resolve labels for a specific set of ids (Filament getOptionLabelUsing).
     */
    public static function labelForId(string $value): ?string
    {
        $row = static::query()
            ->where('id', $value)
            ->select('id')
            ->selectRaw("name->>'en' AS label")
            ->toBase()  // stdClass row, not hydrated Model
            ->first();

        if ($row === null) {
            return null;
        }

        $label = isset($row->label) ? (string) $row->label : $value;

        return $label !== '' ? $label : $value;
    }
}
