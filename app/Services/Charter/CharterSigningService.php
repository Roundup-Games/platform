<?php

namespace App\Services\Charter;

use App\Dto\CharterContent;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Charter signing lifecycle (M070/S02, docs/spec/campaign-charter-concept.md §8):
 * participant signatures pinned to the immutable current version, the owner's
 * countersignature, the owner's signature roster, and the GM-only private
 * boundary answers accessor.
 *
 * Static constructor-free methods per the CharterService/ParticipantService
 * pattern. Ownership derives from the campaign owner row (direct owner_id
 * comparison — R084, never GmRoleService). Mutations are single inserts
 * guarded by pre-checks; the partial unique index
 * session_zero_confirmations_version_user_role_unique is the unconditional
 * race backstop (23505 converges to the duplicate error).
 *
 * Privacy contract (concept §2): private answers reach the campaign owner
 * (GM) and the participant themself — never other participants or guests.
 * privateAnswersFor() is the single choke point; logs carry ids only,
 * never answer content. The legacy confirmation_count counter of surveys
 * is never touched here.
 */
class CharterSigningService
{
    // ── Signing ────────────────────────────────────────

    /**
     * Record a participant's signature on the charter's current version:
     * read attestation + the four §8 agreement items all ticked, plus the
     * optional §2 private boundary answers (normalized — out-of-vocabulary
     * categories dropped, texts trimmed).
     *
     * @param  array<string, mixed>  $input  ['attestations' => array<string, bool>, 'private_answers' => array<string, mixed>]
     *
     * Accepted race (documented, deliberately unlocked): the approved-
     * participant check and the insert are two unlocked reads/writes, so
     * a participant benched or removed milliseconds after the check can
     * still land a signature. Product semantics already accept the
     * benched-after-sign signature (workspaceHealth keeps counting it),
     * so serializing the roster against every bench mutation would buy
     * nothing — the roster math self-heals on the next recompute.
     *
     * Second accepted race (same class): activeVersion() is an unlocked
     * read, so a signer who loaded vN as current can commit a signed
     * row on vN AFTER a material amendment committed on vN+1 (whose
     * resetAgreement already ran). Benign and self-healing — the roster
     * shows that signer a plain pending chip, the gates recompute on
     * vN+1 — but the audit trail permanently carries a signature on a
     * version that was already superseded when written. Locking the
     * version read against amendments would serialize two high-volume,
     * user-paced flows for a cosmetic artifact; not worth it.
     *
     * @throws ValidationException charter not active/published, caller not an approved participant, already signed, or attestation/text validation failed
     */
    public static function sign(User $user, SessionZeroSurvey $charter, array $input): SessionZeroConfirmation
    {
        // The charter route is deliberately outside the not.disabled
        // middleware (capability-link trust), and remember-me session
        // restoration bypasses the runtime guard — so the consent/audit
        // surface re-checks here. A banned account must not land an
        // immutable, version-pinned signature.
        if ($user->isDisabled()) {
            throw ValidationException::withMessages([
                'charter' => __('auth.error_your_account_has_been_disabled'),
            ]);
        }

        $version = self::activeVersion($charter);

        if (! self::isApprovedParticipant($charter, $user)) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_not_participant'),
            ]);
        }

        if (self::hasConfirmation($version, $user, SessionZeroConfirmation::ROLE_PARTICIPANT)) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_already_signed'),
            ]);
        }

        $rules = [];
        foreach (CharterContent::AGREEMENT_ITEMS as $item) {
            $rules['attestations.'.$item] = ['required', 'boolean', 'accepted'];
        }
        // The container rules keep boundaryCategories() iterating a
        // bounded, well-typed shape even on a hand-crafted payload —
        // scalar or nested values are dropped as validation failures,
        // never walked.
        $rules['private_answers'] = ['nullable', 'array'];
        $rules['private_answers.my_lines_categories'] = ['nullable', 'array', 'max:'.count(CharterContent::BOUNDARY_CATEGORIES)];
        $rules['private_answers.my_veils_categories'] = ['nullable', 'array', 'max:'.count(CharterContent::BOUNDARY_CATEGORIES)];
        $rules['private_answers.my_lines_text'] = ['nullable', 'string', 'max:2000'];
        $rules['private_answers.my_veils_text'] = ['nullable', 'string', 'max:2000'];
        $rules['private_answers.note_to_gm'] = ['nullable', 'string', 'max:2000'];

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // The accepted-validation above guarantees attestations is an
        // array with all five items truthy; the re-guard keeps the read
        // type-safe for static analysis.
        $submitted = $input['attestations'] ?? [];
        $attestations = [];
        if (! is_array($submitted)) {
            $submitted = [];
        }
        foreach (CharterContent::AGREEMENT_ITEMS as $item) {
            $attestations[$item] = (bool) ($submitted[$item] ?? false);
        }

        $private = $input['private_answers'] ?? [];
        $privateAnswers = is_array($private) ? [
            'my_lines_categories' => self::boundaryCategories($private['my_lines_categories'] ?? null),
            'my_veils_categories' => self::boundaryCategories($private['my_veils_categories'] ?? null),
            'my_lines_text' => self::optionalText($private['my_lines_text'] ?? null),
            'my_veils_text' => self::optionalText($private['my_veils_text'] ?? null),
            'note_to_gm' => self::optionalText($private['note_to_gm'] ?? null),
        ] : null;

        // An all-empty shape stores NULL, not an empty array: the roster's
        // continuity view (privateAnswersFor) skips null rows and falls
        // through to the participant's previously submitted answers — an
        // all-empty array on a re-sign would otherwise shadow them with
        // "no answers" after exactly the material amendment the carry
        // rule exists for. The re-sign form prefills prior answers
        // (ViewCharter), so reaching here empty means nothing was
        // declared — on either version.
        if ($privateAnswers !== null
            && $privateAnswers['my_lines_categories'] === []
            && $privateAnswers['my_veils_categories'] === []
            && $privateAnswers['my_lines_text'] === null
            && $privateAnswers['my_veils_text'] === null
            && $privateAnswers['note_to_gm'] === null
        ) {
            $privateAnswers = null;
        }

        try {
            $confirmation = $charter->confirmations()->create([
                'session_zero_survey_version_id' => $version->id,
                'user_id' => $user->id,
                'attestations' => $attestations,
                'private_answers' => $privateAnswers,
                'status' => SessionZeroConfirmation::STATUS_SIGNED,
                'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
            ]);
        } catch (QueryException $e) {
            if (! self::isSignatureRaceViolation($e)) {
                throw $e;
            }

            // Concurrent sign won the (version, user, role) race — converge.
            throw ValidationException::withMessages([
                'charter' => __('charter.error_already_signed'),
            ]);
        }

        Log::info('Charter signed', [
            'survey_id' => $charter->id,
            'uuid' => $charter->uuid,
            'campaign_id' => $charter->campaign_id,
            'version' => $version->version_number,
            'user_id' => $user->id,
            'confirmation_id' => $confirmation->id,
        ]);

        return $confirmation;
    }

    // ── Countersignature ───────────────────────────────

    /**
     * The campaign owner's countersignature on the current version. Only
     * allowed once every approved participant has signed that version —
     * silence is never consent, and the GM is never a signer (they
     * countersign; the owner is not required to be a participant).
     *
     * An empty approved roster refuses the countersign: "every
     * participant has signed" is vacuously true at zero participants,
     * but silence is never consent — the mutual-agreement signal
     * (concept §8) requires at least one signed participant to mean
     * anything. Same rule as CharteredEvaluator's proof chain, so the
     * badge cannot be farmed by publish → countersign → archive loops
     * on participant-less campaigns.
     *
     * Accepted race (documented, deliberately unlocked): pending is
     * computed from two unlocked reads, so a participant approved between
     * the roster pluck and the insert can countersign through without
     * having signed. The window is milliseconds, the invariant is soft
     * (the roster view recomputes live and shows the unsigned
     * participant), and double countersigning stays impossible via the
     * unique index — locking the participant roster against every
     * approval would buy nothing proportional.
     *
     * @throws ValidationException caller is not the campaign owner, charter not active, already countersigned, or participants are still pending
     */
    public static function countersign(User $user, SessionZeroSurvey $charter): SessionZeroConfirmation
    {
        // Same disabled-account backstop as sign(): the owner's
        // countersignature is the consent record of record.
        if ($user->isDisabled()) {
            throw ValidationException::withMessages([
                'charter' => __('auth.error_your_account_has_been_disabled'),
            ]);
        }

        if ((string) $charter->campaign?->owner_id !== (string) $user->id) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_not_owner'),
            ]);
        }

        $version = self::activeVersion($charter);

        if (self::hasConfirmation($version, $user, SessionZeroConfirmation::ROLE_GM)) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_already_countersigned'),
            ]);
        }

        $approved = $charter->campaign?->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('user_id') ?? collect();
        $signed = $charter->confirmations()
            ->where('session_zero_survey_version_id', $version->id)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->where('status', SessionZeroConfirmation::STATUS_SIGNED)
            ->whereIn('user_id', $approved)
            ->count();
        if ($approved->isEmpty()) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_countersign_no_participants'),
            ]);
        }

        $pending = $approved->count() - $signed;

        if ($pending > 0) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_countersign_pending_signatures', ['count' => $pending]),
            ]);
        }

        try {
            $confirmation = $charter->confirmations()->create([
                'session_zero_survey_version_id' => $version->id,
                'user_id' => $user->id,
                'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
                'private_answers' => null,
                'status' => SessionZeroConfirmation::STATUS_SIGNED,
                'role' => SessionZeroConfirmation::ROLE_GM,
            ]);
        } catch (QueryException $e) {
            if (! self::isSignatureRaceViolation($e)) {
                throw $e;
            }

            // Concurrent countersign won the race — converge.
            throw ValidationException::withMessages([
                'charter' => __('charter.error_already_countersigned'),
            ]);
        }

        Log::info('Charter countersigned', [
            'survey_id' => $charter->id,
            'uuid' => $charter->uuid,
            'campaign_id' => $charter->campaign_id,
            'version' => $version->version_number,
            'user_id' => $user->id,
            'confirmation_id' => $confirmation->id,
        ]);

        return $confirmation;
    }

    // ── Read accessors ─────────────────────────────────

    /**
     * The owner's signature roster: every approved participant paired with
     * their current-version participant confirmation (null = pending).
     * M070/S03-T05 distinguishes the two unsigned states: a participant
     * whose earlier-version signature a material amendment flipped to
     * pending re-agreement carries reagreementPending = true (distinct GM
     * copy + their carried answers stay readable), while a never-signed
     * participant stays false. Benched/waitlisted/pending/rejected
     * participants are excluded — the roster is the approved table, not
     * the application pipeline. Empty while the charter has no published
     * version.
     *
     * @return Collection<int, array{participant: CampaignParticipant, user: User|null, confirmation: SessionZeroConfirmation|null, reagreementPending: bool}>
     */
    public static function roster(SessionZeroSurvey $charter): Collection
    {
        $version = $charter->currentVersion();
        if ($version === null) {
            return collect();
        }

        $participants = $charter->campaign?->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->with('user')
            ->orderBy('created_at')
            ->get() ?? collect();

        $confirmations = $charter->confirmations()
            ->where('session_zero_survey_version_id', $version->id)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->get()
            ->keyBy('user_id');

        // M070/S03-T05: participants without a current-version signature
        // who hold a pending-re-agreement participant row on an earlier
        // version are mid re-agreement — exactly the state a material
        // amendment's resetAgreement() leaves behind. sign() always writes
        // signed rows and carrySignatures() only copies signed rows onto
        // the current version, so never-signed participants and carried
        // signatures never match.
        $uncoveredUserIds = $participants
            ->filter(fn (CampaignParticipant $participant): bool => $confirmations->get($participant->user_id) === null)
            ->pluck('user_id');

        $reagreementUserIds = $uncoveredUserIds->isEmpty() ? collect() : $charter->confirmations()
            ->join(
                'session_zero_survey_versions as prior_versions',
                'prior_versions.id', '=', 'session_zero_confirmations.session_zero_survey_version_id',
            )
            ->where('session_zero_confirmations.role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->where('session_zero_confirmations.status', SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)
            ->where('prior_versions.version_number', '<', $version->version_number)
            ->whereIn('session_zero_confirmations.user_id', $uncoveredUserIds)
            ->pluck('session_zero_confirmations.user_id');

        return $participants->map(
            fn (CampaignParticipant $participant): array => [
                'participant' => $participant,
                'user' => $participant->user,
                'confirmation' => $confirmations->get($participant->user_id),
                'reagreementPending' => $reagreementUserIds->contains($participant->user_id),
            ],
        );
    }

    /**
     * The viewer's own signature on the current version (null = they still
     * need to sign). Only participant rows — the countersignature is the
     * owner's, never a personal signature state.
     */
    /**
     * Signing progress for one charter's current version — the light,
     * single-charter shape of workspaceHealth's counts (same
     * predicates: approved participants as the denominator; participant-
     * role SIGNED rows on the current version as the numerator). Used
     * by compact surfaces (the game-detail charter card); the workspace
     * keeps its grouped-count query.
     *
     * @return array{approved: int, signed: int, percent: int}
     */
    public static function signingProgress(SessionZeroSurvey $charter): array
    {
        $version = $charter->currentVersion();

        $approved = (int) ($charter->campaign?->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count() ?? 0);

        $signed = $version === null ? 0 : (int) $charter->confirmations()
            ->where('session_zero_survey_version_id', $version->id)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->where('status', SessionZeroConfirmation::STATUS_SIGNED)
            ->count();

        return [
            'approved' => $approved,
            'signed' => $signed,
            'percent' => $approved > 0 ? (int) min(100, round($signed / $approved * 100)) : 0,
        ];
    }

    public static function statusFor(SessionZeroSurvey $charter, User $user): ?SessionZeroConfirmation
    {
        $version = $charter->currentVersion();
        if ($version === null) {
            return null;
        }

        return $charter->confirmations()
            ->where('session_zero_survey_version_id', $version->id)
            ->whereBelongsTo($user)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->first();
    }

    /**
     * Charter health rows for the GM workspace (M070/S04-T03, concept §4
     * mission control): the owner's charters in every lifecycle state,
     * newest activity first (latest 10 by updated_at), each with the
     * roster math the workspace renders — approved participants,
     * signatures pinned to the current version, percent signed, days the
     * current version has been outstanding, and the countersign trail.
     * Ownership derives from the campaign owner row (R084 idiom).
     *
     * Efficient shape for a list view: one charter query (campaign
     * eager-loaded), one version fetch, and grouped counts for the whole
     * set — never a per-charter roster N+1 (the full roster stays the
     * composer's per-charter read).
     *
     * @return array<int, array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     charter_uuid: string,
     *     status: string,
     *     current_version: ?int,
     *     approvedCount: int,
     *     signedCount: int,
     *     pendingCount: int,
     *     percentSigned: int,
     *     daysOutstanding: ?int,
     *     countersigned: bool,
     *     campaign: ?Campaign,
     * }>
     */
    public static function workspaceHealth(User $owner): array
    {
        // "Newest activity" is the last touch on the charter OR its
        // signatures: signatures only write session_zero_confirmations,
        // so ordering by the survey row's updated_at alone ranks a fully
        // signed charter below one merely re-saved last week. Greatest()
        // of both timestamps, newest first.
        $charters = SessionZeroSurvey::query()
            ->whereHas('campaign', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
            ->with('campaign')
            // Correlated max(confirmed_at) inlined — Postgres ORDER BY
            // cannot reference a select alias inside an expression, and
            // the candidate set is already owner-scoped before the sort.
            ->orderByRaw(
                'greatest(session_zero_surveys.updated_at, coalesce('.
                '(select max(sc.confirmed_at) from session_zero_confirmations sc where sc.session_zero_survey_id = session_zero_surveys.id), '.
                'session_zero_surveys.updated_at)) desc'
            )
            ->limit(10)
            ->get();

        if ($charters->isEmpty()) {
            return [];
        }

        // The current version for the whole set in one fetch —
        // currentVersion() runs a per-row query, unusable in a list.
        // Minimal projection: the health math reads id/version_number/
        // published_at only; version rows carry the full charter content
        // snapshot (jsonb, tens of KB), and every version of every
        // charter would ride along without the select.
        $versions = SessionZeroSurveyVersion::query()
            ->whereIn('session_zero_survey_id', $charters->pluck('id'))
            ->select(['id', 'session_zero_survey_id', 'version_number', 'published_at'])
            ->get()
            ->keyBy(fn (SessionZeroSurveyVersion $version): string => $version->session_zero_survey_id.'::'.$version->version_number);

        $currentVersionByCharter = [];
        foreach ($charters as $charter) {
            $currentVersionByCharter[$charter->id] = $versions->get($charter->id.'::'.$charter->current_version);
        }

        $campaignIds = $charters->pluck('campaign_id')->filter()->values();
        $versionIds = collect($currentVersionByCharter)
            ->filter(fn (?SessionZeroSurveyVersion $version): bool => $version !== null)
            ->pluck('id');

        // Grouped counts for the whole set — no per-charter roster queries.
        // Counts land in typed arrays via the is_numeric narrowing idiom
        // (DiscordDigestPublisher pattern) — DB aggregates come back mixed.
        $approvedByCampaign = [];
        foreach (CampaignParticipant::query()
            ->whereIn('campaign_id', $campaignIds)
            ->where('status', ParticipantStatus::Approved->value)
            ->groupBy('campaign_id')
            ->selectRaw('campaign_id, count(*) as approved_count')
            ->pluck('approved_count', 'campaign_id') as $campaignId => $approvedCount) {
            $approvedByCampaign[(string) $campaignId] = is_numeric($approvedCount) ? (int) $approvedCount : 0;
        }

        // Approved-roster semantics of the countersign gate: participant
        // signatures pinned (signed) to the current version.
        $signedByVersion = [];
        foreach (SessionZeroConfirmation::query()
            ->whereIn('session_zero_survey_version_id', $versionIds)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->where('status', SessionZeroConfirmation::STATUS_SIGNED)
            ->groupBy('session_zero_survey_version_id')
            ->selectRaw('session_zero_survey_version_id, count(*) as signed_count')
            ->pluck('signed_count', 'session_zero_survey_version_id') as $versionId => $signedCount) {
            $signedByVersion[(string) $versionId] = is_numeric($signedCount) ? (int) $signedCount : 0;
        }

        // The countersign trail on the current version. Today a GM row
        // there is always signed — pending re-agreement is included
        // defensively so a future amendment-semantics change cannot
        // silently hide a carried countersignature.
        $countersignedVersions = [];
        foreach (SessionZeroConfirmation::query()
            ->whereIn('session_zero_survey_version_id', $versionIds)
            ->where('role', SessionZeroConfirmation::ROLE_GM)
            ->whereIn('status', [SessionZeroConfirmation::STATUS_SIGNED, SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT])
            ->groupBy('session_zero_survey_version_id')
            ->selectRaw('session_zero_survey_version_id, count(*) as countersigned')
            ->pluck('countersigned', 'session_zero_survey_version_id') as $versionId => $countersigned) {
            $countersignedVersions[(string) $versionId] = is_numeric($countersigned) ? (int) $countersigned : 0;
        }

        $rows = [];
        foreach ($charters as $charter) {
            $version = $currentVersionByCharter[$charter->id] ?? null;
            $approved = $approvedByCampaign[(string) $charter->campaign_id] ?? 0;

            // A signer who was benched afterwards keeps their signature,
            // so signed can exceed approved — the clamps below keep the
            // row honest (percent caps at 100, pending floors at 0).
            $signed = $version !== null ? ($signedByVersion[(string) $version->id] ?? 0) : 0;
            $pending = max(0, $approved - $signed);
            $percent = $approved > 0
                ? (int) min(100, round($signed / $approved * 100))
                : 0;

            // Days outstanding tracks the current version's age, and only
            // while somebody still owes a signature — a fully signed (or
            // participant-less) charter is never "outstanding".
            $daysOutstanding = null;
            if ($pending > 0 && $version !== null) {
                $daysOutstanding = (int) floor(abs($version->published_at->diffInDays(now())));
            }

            $rows[] = [
                'campaign_id' => (string) $charter->campaign_id,
                // The eager-loaded model itself — route() takes models,
                // not ids (eloquent-practices convention), and the blade
                // link stays tamper-proof.
                'campaign' => $charter->campaign,
                'campaign_name' => (string) $charter->campaign?->name,
                'charter_uuid' => (string) $charter->uuid,
                'status' => (string) $charter->status,
                'current_version' => $charter->current_version,
                'approvedCount' => $approved,
                'signedCount' => $signed,
                'pendingCount' => $pending,
                'percentSigned' => $percent,
                'daysOutstanding' => $daysOutstanding,
                // Countersigned on the current version — deliberately
                // NOT 'fullySigned' (all participants signed): a
                // mid-campaign joiner after a countersign makes the two
                // claims diverge in both directions.
                'countersigned' => $version !== null && ($countersignedVersions[(string) $version->id] ?? 0) > 0,
            ];
        }

        return $rows;
    }

    /**
     * Participant signatures with their §2 private boundary answers for one
     * version — the single choke point of the privacy contract. The campaign
     * owner (GM) sees the answers; everyone else (participants, guests,
     * uninvolved users) gets an AuthorizationException. Answers are never
     * editable through this or any other surface.
     *
     * Continuity (M070/S03-T05): a material amendment leaves re-agreeing
     * participants without a row on the current version, but the owner keeps
     * seeing each participant's latest submitted answers — typically the
     * pending_reagreement row on the prior version — until they re-sign.
     * Participants with a row on the requested version are never shadowed
     * by older answers, and null-answer rows are skipped so the fallback
     * always surfaces the latest answers actually submitted, byte-for-byte
     * as signed.
     *
     * @return Collection<int, SessionZeroConfirmation>
     *
     * @throws AuthorizationException when the viewer is not the campaign owner
     */
    public static function privateAnswersFor(
        SessionZeroSurvey $charter,
        SessionZeroSurveyVersion $version,
        User $viewer,
    ): Collection {
        if ((string) $charter->campaign?->owner_id !== (string) $viewer->id) {
            throw new AuthorizationException('Only the campaign owner can read private boundary answers.');
        }

        // Current-version rows that actually DECLARED answers. A
        // null-answer row (an untouched re-sign — sign() stores null
        // for an all-empty shape) must neither render as "no answers"
        // nor suppress the participant's prior answers: continuity
        // means the latest actually-submitted answers stand in for it,
        // exactly as for a participant who has not re-signed at all.
        $rows = $charter->confirmations()
            ->where('session_zero_survey_version_id', $version->id)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->whereNotNull('private_answers')
            ->get();

        // Fallback candidates: every earlier-version participant row that
        // actually submitted answers, from users not covered by a row on
        // the requested version (when nobody is covered, every earlier
        // submitter is a candidate — whereNotIn with an empty list would
        // match nothing). Newest-first per user — the bulk form of the
        // latestParticipantConfirmation() ordering contract; keep the
        // two in step.
        $candidates = $charter->confirmations()
            ->join(
                'session_zero_survey_versions as answer_versions',
                'answer_versions.id', '=', 'session_zero_confirmations.session_zero_survey_version_id',
            )
            ->where('session_zero_confirmations.role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->whereNotNull('session_zero_confirmations.private_answers')
            ->where('answer_versions.version_number', '<', $version->version_number)
            ->orderByDesc('answer_versions.version_number')
            ->orderByDesc('session_zero_confirmations.confirmed_at');

        $coveredUserIds = $rows->pluck('user_id');
        if ($coveredUserIds->isNotEmpty()) {
            $candidates->whereNotIn('session_zero_confirmations.user_id', $coveredUserIds);
        }

        $latestByUser = [];
        foreach ($candidates->get(['session_zero_confirmations.*']) as $candidate) {
            $userId = (string) $candidate->user_id;
            if (! isset($latestByUser[$userId])) {
                $latestByUser[$userId] = $candidate;
            }
        }

        return $rows->merge(array_values($latestByUser))->values();
    }

    // ── Guards & helpers ───────────────────────────────

    /**
     * Gate shared by sign() and countersign(): the row must be a campaign
     * charter in the published active state with a resolvable current
     * version to pin to.
     *
     * @throws ValidationException when the charter is not active/published
     */
    private static function activeVersion(SessionZeroSurvey $charter): SessionZeroSurveyVersion
    {
        $version = $charter->isActive() ? $charter->currentVersion() : null;

        if ($version === null) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_charter_not_active'),
            ]);
        }

        return $version;
    }

    /** Is the user an approved participant of the charter's campaign? */
    private static function isApprovedParticipant(SessionZeroSurvey $charter, User $user): bool
    {
        return CampaignParticipant::query()
            ->where('campaign_id', $charter->campaign_id)
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    /** Does the user already hold a confirmation in the given role on the version? */
    private static function hasConfirmation(SessionZeroSurveyVersion $version, User $user, string $role): bool
    {
        return SessionZeroConfirmation::query()
            ->where('session_zero_survey_version_id', $version->id)
            ->whereBelongsTo($user)
            ->where('role', $role)
            ->exists();
    }

    /**
     * Did the insert lose the (version, user, role) race? Postgres
     * SQLSTATE 23505 naming the partial unique index
     * (CharterService::isCharterRaceViolation pattern).
     */
    private static function isSignatureRaceViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505'
            && str_contains($e->getMessage(), 'session_zero_confirmations_version_user_role_unique');
    }

    /**
     * The LATEST-ROW-ACROSS-VERSIONS contract (single user): highest
     * version_number wins, latest confirmed_at breaks (theoretical)
     * ties — the ordering amend() carries signatures with. This is the
     * shared seam for every "latest prior participant row" read (the
     * re-agreement banner, the re-sign prefill); the bulk variants
     * (carrySignatures, roster, privateAnswersFor) implement the same
     * ordering over many users at once and must not drift from it.
     *
     * @param  bool  $withAnswersOnly  skip null-answer rows (the prefill
     *                                 view — nothing to prefill otherwise)
     */
    public static function latestParticipantConfirmation(
        SessionZeroSurvey $charter,
        User $user,
        bool $withAnswersOnly = false,
    ): ?SessionZeroConfirmation {
        /** @var SessionZeroConfirmation|null $confirmation */
        $confirmation = $charter->confirmations()
            ->join(
                'session_zero_survey_versions as signed_versions',
                'signed_versions.id', '=', 'session_zero_confirmations.session_zero_survey_version_id',
            )
            ->where('session_zero_confirmations.user_id', $user->id)
            ->where('session_zero_confirmations.role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->when($withAnswersOnly, fn ($query) => $query->whereNotNull('session_zero_confirmations.private_answers'))
            ->orderByDesc('signed_versions.version_number')
            ->orderByDesc('session_zero_confirmations.confirmed_at')
            ->first(['session_zero_confirmations.*']);

        return $confirmation;
    }

    /**
     * §2 private category checklist: keep only BOUNDARY_CATEGORIES members,
     * drop duplicates, reindex (tolerant — junk and out-of-vocabulary
     * entries are dropped, never rejected).
     *
     * @return array<int, string>
     */
    private static function boundaryCategories(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $kept = [];
        foreach ($value as $item) {
            $string = is_scalar($item) ? (string) $item : null;
            if ($string !== null
                && in_array($string, CharterContent::BOUNDARY_CATEGORIES, true)
                && ! in_array($string, $kept, true)) {
                $kept[] = $string;
            }
        }

        return $kept;
    }

    /** Trimmed non-empty string or null (optional free-text answers). */
    private static function optionalText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
