<?php

namespace App\Services\Charter;

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\CharterTemplate;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Campaign charter draft → publish lifecycle (M070).
 *
 * Single home for the lifecycle so the composer component stays thin
 * (ParticipantService pattern) and S03 amendments extend one place.
 * Every mutation is guarded (draft-only) and publish runs in a
 * transaction: the immutable version snapshot is inserted and the
 * charter row flipped to active/current_version atomically.
 *
 * Ownership derives from the campaign owner (R084: charter authoring is
 * free-tier safety — never gated on GM membership/active GM role).
 * saveDraft/publish operate on a charter row the caller resolved
 * owner-gated; start() re-authorizes server-side because it receives
 * the user directly.
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
        if ((string) $campaign->owner_id !== (string) $user->id) {
            throw new AuthorizationException('Only the campaign owner can start a charter.');
        }

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

        $content = match ($mode) {
            'blank' => CharterContent::blank(),
            'template' => CharterContent::fromArray(self::resolveTemplateSeed($user, $seedId)->content ?? []),
            'previous' => CharterContent::fromArray(self::resolvePreviousSeed($user, $seedId)->content ?? []),
            default => CharterContent::quickStart($campaign),
        };

        try {
            $charter = $campaign->charter()->create([
                'kind' => 'charter',
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
     * @throws ValidationException when the charter is not a draft
     */
    public static function saveDraft(SessionZeroSurvey $charter, string $title, array $content): void
    {
        if (! $charter->isDraft()) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_only_draft_editable'),
            ]);
        }

        $charter->update([
            'title' => $title,
            'content' => CharterContent::fromArray($content)->toArray(),
        ]);
    }

    /**
     * Publish the draft: insert the immutable version snapshot (schema v1)
     * and flip the charter to active/current_version atomically. Only
     * drafts publish — an active charter is amendment territory (S03).
     *
     * @param  array<string, mixed>  $content
     *
     * @throws ValidationException when the charter is not a draft or the content fails full validation
     */
    public static function publish(SessionZeroSurvey $charter, string $title, array $content): SessionZeroSurveyVersion
    {
        return DB::transaction(function () use ($charter, $title, $content): SessionZeroSurveyVersion {
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
            // Draft charters always belong to a campaign; an orphaned
            // non-charter draft refuses here instead of locking garbage.
            $ownerId = $charter->campaign?->owner_id;

            if ($ownerId === null) {
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_already_published'),
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

            $validator = Validator::make($normalized, CharterContent::rules());
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $versionNumber = ($charter->current_version ?? 0) + 1;

            $version = $charter->versions()->create([
                'version_number' => $versionNumber,
                'content' => $normalized,
                'content_schema' => CharterContent::SCHEMA_VERSION,
                'changelog' => null,
                'published_at' => now(),
            ]);

            $charter->update([
                'title' => $title,
                'content' => $normalized,
                'status' => 'active',
                'current_version' => $versionNumber,
            ]);

            Log::info('Charter published', [
                'survey_id' => $charter->id,
                'uuid' => $charter->uuid,
                'campaign_id' => $charter->campaign_id,
                'gm_profile_id' => $charter->gm_profile_id,
                'version' => $versionNumber,
            ]);

            return $version;
        });
    }

    /**
     * Archive a finished charter (M070/S04): flip it out of the active
     * roster so it stops consuming a free-tier slot (R084). Owner-gated
     * through the campaign owner (R084 idiom, never GmRoleService) and
     * restricted to active charters — drafts are abandoned by deleting
     * nothing, archived charters are frozen history. Delegates to the
     * model's archive() helper; the immutable version rows stay put and
     * the S02/S03 gates keep refusing sign/amend on archived rows.
     */
    public static function archive(User $user, SessionZeroSurvey $charter): void
    {
        if ((string) $charter->campaign?->owner_id !== (string) $user->id) {
            throw new AuthorizationException('Only the campaign owner can archive a charter.');
        }

        if (! $charter->isActive()) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_only_active_archivable'),
            ]);
        }

        $charter->archive();

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
     *
     * @throws ValidationException when the charter is not an active published charter, title/changelog/content validation fails, or a concurrent amend won the version race
     */
    public static function amend(SessionZeroSurvey $charter, string $title, array $content, string $changelog): SessionZeroSurveyVersion
    {
        return DB::transaction(function () use ($charter, $title, $content, $changelog): SessionZeroSurveyVersion {
            $previous = null;
            if ($charter->isCharter() && $charter->isActive()) {
                $previous = $charter->currentVersion();
            }

            if ($previous === null) {
                throw ValidationException::withMessages([
                    'charter' => __('charter.error_only_active_amendable'),
                ]);
            }

            $normalized = CharterContent::fromArray($content)->toArray();
            $changelog = trim($changelog);

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

            Log::info('Charter amended', [
                'survey_id' => $charter->id,
                'uuid' => $charter->uuid,
                'campaign_id' => $charter->campaign_id,
                'version' => $versionNumber,
                'is_material' => $isMaterial,
            ]);

            return $version;
        });
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Resolve the 'template' start seed: a saved CharterTemplate owned
     * by the caller. An unknown or foreign id (and a missing one — the
     * composer guard aside, start() is callable directly) is refused with
     * the same message so ids are never enumerated through the error
     * copy. Non-uuid ids are "not found" via UuidBuilder (INV-10), never
     * a SQL error.
     */
    private static function resolveTemplateSeed(User $user, ?string $seedId): CharterTemplate
    {
        $template = is_string($seedId) && $seedId !== ''
            ? CharterTemplate::query()->whereKey($seedId)->first()
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
     * excluding the current campaign; service-side the ownership check is
     * the gate (the current campaign's own charter never reaches here —
     * start() returned it as the idempotent existing row above).
     */
    private static function resolvePreviousSeed(User $user, ?string $seedId): SessionZeroSurvey
    {
        $previous = is_string($seedId) && $seedId !== ''
            ? SessionZeroSurvey::query()->charter()->whereKey($seedId)->first()
            : null;

        if ($previous === null || (string) $previous->campaign?->owner_id !== (string) $user->id) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_previous_not_yours'),
            ]);
        }

        return $previous;
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
        // wins, latest confirmed_at breaks (theoretical) ties.
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
}
