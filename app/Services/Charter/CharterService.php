<?php

namespace App\Services\Charter;

use App\Dto\CharterContent;
use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\CharterTemplate;
use App\Models\Location;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Notifications\CharterAmended;
use App\Notifications\CharterPublished;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Campaign charter draft → publish lifecycle (M070).
 *
 * Single home for the lifecycle so the composer component stays thin
 * (ParticipantService pattern) and S03 amendments extend one place.
 * Every mutation is guarded twice: state guards (draft-only, active-only)
 * AND per-mutation authorization — each public method takes the acting
 * user and re-resolves CampaignPolicy::manageCharter against the
 * charter's campaign, so no caller (component, command, queue job) can
 * inherit an unguarded write path. Publish runs in a transaction: the
 * immutable version snapshot is inserted and the charter row flipped to
 * active/current_version atomically.
 *
 * Ownership derives from the campaign owner through the policy (R084:
 * charter authoring is free-tier safety — never gated on GM
 * membership/active GM role; global admins pass via the policy's
 * before(), consistent with every other campaign ability).
 *
 * The one membership-coupled rule (R084/R085) is the concurrent
 * active-charter cap, consulted in start() and inside the publish
 * transaction through CharterMemberService — drafts, amendments, and
 * signatures never ask about membership.
 */
class CharterService
{
    // ── Accessors ──────────────────────────────────────

    /**
     * The campaign's charter, any lifecycle state (draft/active/archived),
     * or null when the campaign has none yet. Used by the composer and
     * the campaign detail entry card.
     */
    public static function charterFor(Campaign $campaign): ?SessionZeroSurvey
    {
        return $campaign->charter()->first();
    }

    // ── Lifecycle ──────────────────────────────────────

    /**
     * Start authoring a charter for the campaign. Idempotent: when a
     * charter already exists it is returned unchanged (the caller renders
     * draft or published state). The DB partial unique index
     * session_zero_surveys_charter_campaign_unique is the race backstop —
     * a lost race re-queries and returns the winner's row.
     *
     * Modes (S04): 'quickstart' prefills from the campaign, 'blank' seeds
     * empty defaults, and the two member conveniences seed from an
     * existing charter — 'template' from a saved CharterTemplate owned by
     * the caller, 'previous' from a charter on a campaign the caller owns.
     * Seed content runs through the same tolerant normalizer as every
     * other content write, so seeded drafts are schema-v1 shaped from the
     * first keystroke. The title always defaults to the target campaign's
     * name (a template is reusable content, not a title).
     *
     * @param  'quickstart'|'blank'|'template'|'previous'  $mode
     *
     * @throws AuthorizationException when the caller does not own the campaign
     * @throws ValidationException at the free-tier cap (before any seed resolution) or when the seed id is missing/foreign
     */
    public static function start(
        User $user,
        Campaign $campaign,
        string $mode = 'quickstart',
        ?string $seedId = null,
    ): SessionZeroSurvey {
        Gate::forUser($user)->authorize('manageCharter', $campaign);

        $existing = self::charterFor($campaign);
        if ($existing !== null) {
            return $existing;
        }

        // R084/R085: free owners cap at 2 concurrent ACTIVE charters
        // (members unlimited). Drafting itself stays free — this only
        // stops a third charter from being started, and it applies before
        // any seed resolution so a refused start never leaks whether the
        // seed id existed.
        if (CharterMemberService::atCharterLimit($user)) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_charter_limit_reached', [
                    'limit' => CharterMemberService::FREE_ACTIVE_CHARTERS,
                ]),
            ]);
        }

        // The S04 conveniences are member-only at the SERVICE layer, not
        // just the composer's hidden start cards: CharterComposer::
        // startCharter() forwards the mode verbatim from wire:click, so
        // gating only the UI would leave a free owner the server-side
        // reuse path. One choke point (canUseConveniences, the D223
        // seam) keeps template reuse, previous-charter reuse, and the
        // concurrency cap tighten together when pricing lands.
        if (in_array($mode, ['template', 'previous'], true)
            && ! CharterMemberService::canUseConveniences($user)) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_seeds_member_only'),
            ]);
        }

        $content = match ($mode) {
            'blank' => CharterContent::blank(),
            'template' => CharterContent::fromArray(self::resolveTemplateSeed($user, $seedId)->content ?? []),
            'previous' => CharterContent::fromArray(self::resolvePreviousSeed($user, $seedId)->content ?? []),
            default => CharterContent::quickStart($campaign),
        };

        try {
            $charter = $campaign->charter()->create([
                'gm_profile_id' => $user->gmProfile?->id,
                'title' => (string) $campaign->name,
                'status' => 'draft',
                'content' => $content->toArray(),
            ]);
        } catch (QueryException $e) {
            if (! self::isCharterRaceViolation($e)) {
                throw $e;
            }

            // Concurrent start won the race — converge on their row.
            $charter = self::charterFor($campaign);
            if ($charter === null) {
                throw $e;
            }

            return $charter;
        }

        Log::info('Charter started', [
            'survey_id' => $charter->id,
            'uuid' => $charter->uuid,
            'campaign_id' => $campaign->id,
            'gm_profile_id' => $charter->gm_profile_id,
            'mode' => $mode,
            'seed_id' => $seedId,
        ]);

        return $charter;
    }

    /**
     * Save draft content. Content is normalized through the tolerant
     * CharterContent normalizer (unknown keys dropped, defaults filled)
     * before persisting, so drafts stay schema-v1 shaped.
     *
     * @param  array<string, mixed>  $content
     *
     * @throws AuthorizationException when the actor may not manage this charter's campaign
     * @throws ValidationException when the charter is not a draft
     */
    public static function saveDraft(User $actor, SessionZeroSurvey $charter, string $title, array $content): void
    {
        self::authorizeCharter($actor, $charter);

        if (! $charter->isDraft()) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_only_draft_editable'),
            ]);
        }

        // Same boundary rule as amend(): the title column is
        // varchar(255) — an unvalidated autosave past it would surface
        // as a Postgres error (500), not a form error. Drafts tolerate
        // everything else (the tolerant normalizer fills gaps); the
        // title is the one always-written free-text field. Trimmed
        // first: whitespace-only must fail `required`, never publish.
        $title = trim($title);
        $validator = Validator::make(['title' => $title], [
            'title' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Conditional update: the isDraft() read above races a
        // concurrent publish() (which flips status inside its own
        // transaction) — an unconditional update here would land draft
        // content on the just-published charter without a version
        // snapshot. Writing with the status guard makes the check and
        // the write one atomic step; zero affected rows means the
        // draft window closed mid-flight.
        $updated = SessionZeroSurvey::query()
            ->whereKey($charter->getKey())
            ->where('status', 'draft')
            ->update([
                'title' => $title,
                'content' => CharterContent::fromArray($content)->toArray(),
            ]);

        if ($updated === 0) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_only_draft_editable'),
            ]);
        }
    }

    /**
     * Publish the draft: insert the immutable version snapshot (schema v1)
     * and flip the charter to active/current_version atomically. Only
     * drafts publish — an active charter is amendment territory (S03).
     *
     * @param  array<string, mixed>  $content
     *
     * @throws AuthorizationException when the actor may not manage this charter's campaign
     * @throws ValidationException when the charter is not a draft or the content fails full validation
     */
    public static function publish(User $actor, SessionZeroSurvey $charter, string $title, array $content): SessionZeroSurveyVersion
    {
        self::authorizeCharter($actor, $charter);

        $version = DB::transaction(function () use ($charter, $title, $content): SessionZeroSurveyVersion {
            if (! $charter->isDraft()) {
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_already_published'),
                ]);
            }

            // Authoritative free-tier gate (R084/R085): serialize
            // concurrent publishes per owner by locking the owner row,
            // then re-count actives inside the transaction — a draft
            // flipping active consumes a slot, so a third draft started
            // before the cap was hit must not be able to publish past it.
            // authorizeCharter() ahead of the transaction already refused
            // orphans; this re-check only covers the deleted-mid-flight
            // race and carries its own message — never the
            // already-published copy (which would misreport the state).
            $ownerId = $charter->campaign?->owner_id;

            if ($ownerId === null) {
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_charter_orphaned'),
                ]);
            }

            $owner = User::query()
                ->whereKey($ownerId)
                ->lockForUpdate()
                ->firstOrFail();

            if (CharterMemberService::atCharterLimit($owner)) {
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_charter_limit_reached', [
                        'limit' => CharterMemberService::FREE_ACTIVE_CHARTERS,
                    ]),
                ]);
            }

            $normalized = CharterContent::fromArray($content)->toArray();

            // Title validated on EVERY persisting mutation, not just
            // saveDraft/amend: publish() also writes the varchar(255)
            // column, and a future direct service caller must hit the
            // form error, never a Postgres "value too long" 500 — the
            // same boundary rule saveDraft() documents.
            $title = trim($title);

            $validator = Validator::make(
                array_merge($normalized, ['title' => $title]),
                array_merge(CharterContent::rules(), [
                    'title' => ['required', 'string', 'max:255'],
                ]),
            );
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            // The venue ownership gate (D079): the version this publish
            // stamps is immutable and publicly readable from here on.
            self::assertVenueDisclosable($normalized, $charter);

            $versionNumber = ($charter->current_version ?? 0) + 1;

            try {
                $version = $charter->versions()->create([
                    'version_number' => $versionNumber,
                    'content' => $normalized,
                    'content_schema' => CharterContent::SCHEMA_VERSION,
                    'changelog' => null,
                    'published_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! self::isAmendmentRaceViolation($e)) {
                    throw $e;
                }

                // Two racing publishes both saw isDraft(); the unique
                // (survey, version_number) index let exactly one win —
                // converge on the duplicate error instead of leaking a
                // 500, exactly like the amend path.
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_already_published'),
                ]);
            }

            $charter->update([
                'title' => $title,
                'content' => $normalized,
                'status' => 'active',
                'current_version' => $versionNumber,
            ]);

            return $version;
        });

        // Audit line AFTER commit: an in-transaction log would survive
        // a trailing rollback and claim a publish that never happened —
        // exactly the failure path where forensics need the truth.
        Log::info('Charter published', [
            'survey_id' => $charter->id,
            'uuid' => $charter->uuid,
            'campaign_id' => $charter->campaign_id,
            'gm_profile_id' => $charter->gm_profile_id,
            'version' => $version->version_number,
        ]);

        // D234 closure: tell approved participants it is their turn to
        // read and sign — after commit, so a queued channel can never
        // read a rolled-back charter.
        self::notifyApprovedParticipants(
            $charter,
            fn (Campaign $campaign) => new CharterPublished($campaign, $charter),
            NotificationCategory::CharterPublished,
        );

        return $version;
    }

    /**
     * Archive a finished charter (M070/S04): flip it out of the active
     * roster so it stops consuming a free-tier slot (R084). Owner-gated
     * through the campaign owner (R084 idiom, never GmRoleService) and
     * restricted to active charters — drafts are abandoned by deleting
     * nothing, archived charters are frozen history. This is the ONE
     * guarded archive path — a conditional, owner-checked write (the
     * model's bare archive() helper is a raw writer with no guards and
     * no lifecycle check); the immutable version rows stay put and
     * the S02/S03 gates keep refusing sign/amend on archived rows.
     */
    public static function archive(User $user, SessionZeroSurvey $charter): void
    {
        self::authorizeCharter($user, $charter);

        // Conditional write (the saveDraft idiom): the in-memory
        // isActive() read races a concurrent archive from another tab
        // (double-write) or an amend-publish (which must stay active —
        // the version index owns that transition). Zero affected rows
        // means the row was no longer active at write time: refuse
        // atomically instead of acting on stale state.
        $archived = SessionZeroSurvey::query()
            ->whereKey($charter->getKey())
            ->where('status', 'active')
            ->update(['status' => 'archived']);

        if ($archived === 0) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_only_active_archivable'),
            ]);
        }

        Log::info('Charter archived', [
            'survey_id' => $charter->id,
            'uuid' => $charter->uuid,
            'campaign_id' => $charter->campaign_id,
        ]);
    }

    /**
     * Amend the published charter (M070/S03): insert the next immutable
     * version snapshot with its changelog and repoint the charter row —
     * versions stay append-only. Material amendments (§1 tone axes or
     * themes, or any §2–§5 section) flip every signed confirmation —
     * participant signatures and the GM countersignature — to pending
     * re-agreement, resetting the roster. Minor amendments carry each
     * (user, role)'s latest signature onto the new version so the roster,
     * signed card, and countersignature line stay green through the
     * unchanged S02 read paths. Title and logistics/§7/typo-level edits
     * are minor (concept §3).
     *
     * @param  array<string, mixed>  $content
     * @param  int|null  $expectedBaseVersion  Optimistic-concurrency guard: the
     *                                         current_version the caller's edits were built against. When
     *                                         non-null and the locked re-read moved on (another tab or GM
     *                                         published an amendment in between), the call refuses instead
     *                                         of silently inserting a new version built from stale content
     *                                         — which would revert the intervening amendment wholesale.
     *                                         Null asserts the caller holds a freshly loaded charter and
     *                                         is the only writer (setup helpers, service-to-service).
     *
     * @throws AuthorizationException when the actor may not manage this charter's campaign
     * @throws ValidationException when the charter is not an active published charter, title/changelog/content validation fails, the expected base version is stale, or a concurrent amend won the version race
     */
    public static function amend(User $actor, SessionZeroSurvey $charter, string $title, array $content, string $changelog, ?int $expectedBaseVersion = null): SessionZeroSurveyVersion
    {
        self::authorizeCharter($actor, $charter);

        $version = DB::transaction(function () use ($charter, $title, $content, $changelog, $expectedBaseVersion): SessionZeroSurveyVersion {
            // Lock the charter row and re-check liveness from the
            // locked read: archive() flips status via a plain UPDATE,
            // which blocks on this lock and lands only AFTER the
            // amendment commits (archived with the new version —
            // frozen history includes it). Without the lock, the
            // in-memory isActive() check could race a concurrent
            // archive() committing mid-transaction and stamp an
            // amendment (and its resetAgreement) onto a charter that is
            // already frozen — the one interleave the version-race
            // unique index cannot catch (archive writes no version).
            $charter = SessionZeroSurvey::query()
                ->whereKey($charter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $charter->isActive() ? $charter->currentVersion() : null;

            if ($previous === null) {
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_only_active_amendable'),
                ]);
            }

            // Optimistic concurrency (the stale-tab guard): the composer's
            // edits are hydrated from the version it started amending
            // against. If another amendment landed since, inserting this
            // snapshot as vN+1 would silently REVERT it in the live
            // charter — the version unique index only catches same-number
            // races, not content built on an outdated base.
            if ($expectedBaseVersion !== null && (int) $charter->current_version !== $expectedBaseVersion) {
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_amended_concurrently'),
                ]);
            }

            $normalized = CharterContent::fromArray($content)->toArray();
            $changelog = trim($changelog);
            $title = trim($title);

            $validator = Validator::make(
                array_merge($normalized, ['title' => $title, 'changelog' => $changelog]),
                array_merge(CharterContent::rules(), [
                    'title' => ['required', 'string', 'max:255'],
                    'changelog' => ['required', 'string', 'max:2000'],
                ]),
            );
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            // The venue ownership gate (D079) — same rule as publish():
            // an amendment may also move the venue.
            self::assertVenueDisclosable($normalized, $charter);

            $isMaterial = CharterContent::isMaterialChange($previous->content, $normalized);
            $versionNumber = ($charter->current_version ?? 0) + 1;

            try {
                $version = $charter->versions()->create([
                    'version_number' => $versionNumber,
                    'content' => $normalized,
                    'content_schema' => CharterContent::SCHEMA_VERSION,
                    'changelog' => $changelog,
                    'is_material' => $isMaterial,
                    'published_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! self::isAmendmentRaceViolation($e)) {
                    throw $e;
                }

                // A concurrent amend published this version number first —
                // converge on the duplicate error instead of leaking the race.
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_amended_concurrently'),
                ]);
            }

            $charter->update([
                'title' => $title,
                'content' => $normalized,
                'current_version' => $versionNumber,
            ]);

            if ($isMaterial) {
                self::resetAgreement($charter, $version);
            } else {
                self::carrySignatures($charter, $version);
            }

            return $version;
        });

        // Audit line AFTER commit — same publish() discipline.
        Log::info('Charter amended', [
            'survey_id' => $charter->id,
            'uuid' => $charter->uuid,
            'campaign_id' => $charter->campaign_id,
            'version' => $version->version_number,
            'is_material' => $version->is_material,
        ]);

        // D234 closure: material amendments reset every signature — the
        // notification copy says "review and re-sign"; minor ones carry
        // signatures and stay informational.
        self::notifyApprovedParticipants(
            $charter,
            fn (Campaign $campaign) => new CharterAmended($campaign, $charter, $version, (bool) $version->is_material),
            NotificationCategory::CharterAmended,
        );

        return $version;
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Resolve the 'template' start seed: a saved CharterTemplate owned
     * by the caller. An unknown, foreign, or non-uuid id (and a missing
     * one — the composer guard aside, start() is callable directly) is
     * refused with the same message so ids are never enumerated through
     * the error copy. uuid_or_null() gate on purpose: UuidBuilder only
     * guards find() lookups, and whereKey()->first() binds the raw
     * value — without the gate a malformed id raises 22P02 and the
     * bootstrap backstop renders it as a 404 page instead of this
     * inline refusal.
     */
    private static function resolveTemplateSeed(User $user, ?string $seedId): CharterTemplate
    {
        $seedUuid = uuid_or_null($seedId);

        $template = $seedUuid !== null
            ? CharterTemplate::query()->whereKey($seedUuid)->first()
            : null;

        if ($template === null || (string) $template->user_id !== (string) $user->id) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_template_not_yours'),
            ]);
        }

        return $template;
    }

    /**
     * Resolve the 'previous' start seed: a charter on a campaign the
     * caller owns. The start-screen select offers published charters
     * excluding the current campaign; service-side the ownership check
     * is the gate (the current campaign's own charter never reaches
     * here — start() returned it as the idempotent existing row above).
     * Same uuid_or_null() gate as resolveTemplateSeed: malformed ids
     * must refuse inline, not surface as a 22P02/404.
     */
    private static function resolvePreviousSeed(User $user, ?string $seedId): SessionZeroSurvey
    {
        $seedUuid = uuid_or_null($seedId);

        $previous = $seedUuid !== null
            ? SessionZeroSurvey::query()->whereKey($seedUuid)->first()
            : null;

        if ($previous === null || (string) $previous->campaign?->owner_id !== (string) $user->id) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_previous_not_yours'),
            ]);
        }

        return $previous;
    }

    /**
     * Venue ownership gate: logistics.location_id must resolve to a
     * location the campaign owner may already disclose — the venues of
     * their own campaigns and games, or this campaign's own. The
     * composer offers exactly that set; publish/amend re-verify it
     * server-side so a crafted autosave can never pin a private
     * third-party venue uuid into a publicly readable immutable version
     * (the D079 location-disclosure model). Drafts tolerate it — draft
     * rows render owner-only.
     *
     * @param  array<string, mixed>  $normalized  CharterContent::fromArray()->toArray() output
     */
    private static function assertVenueDisclosable(array $normalized, SessionZeroSurvey $charter): void
    {
        $logistics = $normalized['logistics'] ?? [];
        $venueMode = is_array($logistics) ? ($logistics['venue_mode'] ?? null) : null;
        $locationId = is_array($logistics) ? ($logistics['location_id'] ?? null) : null;

        if ($venueMode !== 'location' || ! is_string($locationId) || $locationId === '') {
            return;
        }

        $campaign = $charter->campaign;

        if ($campaign === null) {
            // Orphaned charter: publish()/amend() refuse these before the
            // gate runs, but the helper must not assume it.
            return;
        }

        $ownerId = (string) $campaign->owner_id;
        $campaignLocationId = $campaign->location_id;

        // The same shape the composer's picker offers (single-sourced
        // in Location::scopeDisclosableTo): a venue the owner's table
        // already plays at, or the campaign's own.
        $disclosable = Location::query()
            ->whereKey($locationId)
            ->disclosableTo($ownerId, $campaignLocationId)
            ->exists();

        if (! $disclosable) {
            throw ValidationException::withMessages([
                'logistics.location_id' => __('charter.error_location_not_available'),
            ]);
        }
    }

    /**
     * Fan a charter notification out to the campaign's approved
     * participants — the same audience the sign gate admits
     * (error_not_participant) — excluding the owner (their own action
     * needs no notification). The factory closure receives the
     * non-null campaign and builds a FRESH instance per recipient:
     * NotificationService resolves and attaches the recipient's
     * channels onto the instance, so a shared one would deliver
     * recipient N the channels of recipient N-1
     * (GameBulletinObserverFanoutTest lesson). NotificationService::send
     * is error-resilient (logs, never throws), so a dispatch failure can
     * never fail the publish/amend itself.
     *
     * @param  callable(Campaign): Notification  $make
     */
    private static function notifyApprovedParticipants(SessionZeroSurvey $charter, callable $make, NotificationCategory $category): void
    {
        $campaign = $charter->campaign;

        if ($campaign === null) {
            return;
        }

        $recipients = [];
        foreach (CampaignParticipant::query()->whereBelongsTo($campaign)->where('status', 'approved')->with('user')->get() as $participant) {
            $user = $participant->user;

            if ($user instanceof User && $user->id !== $campaign->owner_id) {
                $recipients[] = $user;
            }
        }

        if ($recipients === []) {
            return;
        }

        $notifications = app(NotificationService::class);

        foreach ($recipients as $recipient) {
            $notifications->send($recipient, $make($campaign), $category);
        }
    }

    /**
     * Material amendment: flip every signed confirmation pinned to any
     * earlier version — participant signatures and the GM countersignature
     * — to pending re-agreement (concept §3). The roster resets; re-signing
     * targets the new current version through the unchanged S02 read paths
     * (countersign gate re-arms via the all-signed-on-current-version
     * check). Rows already pending re-agreement keep their status, and
     * legacy rows (null version pin) never match — NULL != x is not true
     * in SQL, and charters carry no legacy confirmations anyway. This is
     * the only permitted mutation of an existing confirmation row; the
     * version rows themselves stay immutable.
     */
    private static function resetAgreement(SessionZeroSurvey $charter, SessionZeroSurveyVersion $version): void
    {
        $charter->confirmations()
            ->where('session_zero_survey_version_id', '!=', $version->id)
            ->where('status', SessionZeroConfirmation::STATUS_SIGNED)
            ->update(['status' => SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT]);
    }

    /**
     * Minor amendment: carry each (user, role)'s latest signature from
     * earlier versions onto the new version — attestations and private
     * answers copied byte-for-byte, confirmed_at stamped now (the model's
     * creating hook) recording when the signature became bound to this
     * version. The originals remain untouched as audit history. Pairs
     * whose latest row is pending re-agreement — or who never signed — get
     * nothing: they still owe a signature. At most one row per (user,
     * role) is created (latest wins via the version_number/confirmed_at
     * sort), so the partial unique index
     * session_zero_confirmations_version_user_role_unique backstops races.
     */
    private static function carrySignatures(SessionZeroSurvey $charter, SessionZeroSurveyVersion $version): void
    {
        // Newest-first across earlier versions: highest version_number
        // wins, latest confirmed_at breaks (theoretical) ties — the bulk
        // form of CharterSigningService::latestParticipantConfirmation()'s
        // ordering contract; keep the two in step.
        $candidates = $charter->confirmations()
            ->join(
                'session_zero_survey_versions as earlier_versions',
                'earlier_versions.id', '=', 'session_zero_confirmations.session_zero_survey_version_id',
            )
            ->where('earlier_versions.id', '!=', $version->id)
            ->orderByDesc('earlier_versions.version_number')
            ->orderByDesc('session_zero_confirmations.confirmed_at')
            ->get(['session_zero_confirmations.*']);

        $latestByPair = [];
        foreach ($candidates as $confirmation) {
            $pairKey = $confirmation->user_id.'::'.$confirmation->role;
            if (! isset($latestByPair[$pairKey])) {
                $latestByPair[$pairKey] = $confirmation;
            }
        }

        foreach ($latestByPair as $source) {
            if ($source->status !== SessionZeroConfirmation::STATUS_SIGNED) {
                continue;
            }

            $charter->confirmations()->create([
                'session_zero_survey_version_id' => $version->id,
                'user_id' => $source->user_id,
                'attestations' => $source->attestations,
                'private_answers' => $source->private_answers,
                'status' => SessionZeroConfirmation::STATUS_SIGNED,
                'role' => $source->role,
            ]);
        }
    }

    /**
     * Did the version insert lose the concurrent-amend race? Postgres
     * SQLSTATE 23505 naming the (survey, version_number) unique index —
     * matched on a truncation-safe prefix because Postgres truncates
     * index names at 63 bytes (cf. the legacy confirmations index note
     * in 2026_10_19_120000_evolve_session_zero_confirmations_for_charter_signing).
     */
    private static function isAmendmentRaceViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505'
            && str_contains($e->getMessage(), 'session_zero_survey_id_version_');
    }

    /**
     * Did the query lose the one-charter-per-campaign race? Postgres
     * SQLSTATE 23505 naming the partial unique index.
     */
    private static function isCharterRaceViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505'
            && str_contains($e->getMessage(), 'session_zero_surveys_charter_campaign_unique');
    }

    /**
     * Per-mutation re-authorization: the actor must hold
     * CampaignPolicy::manageCharter on the charter's campaign. An
     * orphaned charter (campaign deleted) is never manageable — there is
     * no owner left to derive the ability from.
     */
    private static function authorizeCharter(User $actor, SessionZeroSurvey $charter): void
    {
        $campaign = $charter->campaign;

        if ($campaign === null) {
            throw new AuthorizationException('This charter has no campaign to authorize against.');
        }

        Gate::forUser($actor)->authorize('manageCharter', $campaign);
    }
}
