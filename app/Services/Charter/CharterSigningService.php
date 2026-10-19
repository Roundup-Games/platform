<?php

namespace App\Services\Charter;

use App\Dto\CharterContent;
use App\Enums\ParticipantStatus;
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
     * @throws ValidationException charter not active/published, caller not an approved participant, already signed, or attestation/text validation failed
     */
    public static function sign(User $user, SessionZeroSurvey $charter, array $input): SessionZeroConfirmation
    {
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
     * @throws ValidationException caller is not the campaign owner, charter not active, already countersigned, or participants are still pending
     */
    public static function countersign(User $user, SessionZeroSurvey $charter): SessionZeroConfirmation
    {
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
     *     fullySigned: bool,
     * }>
     */
    public static function workspaceHealth(User $owner): array
    {
        $charters = SessionZeroSurvey::query()
            ->charter()
            ->whereHas('campaign', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
            ->with('campaign')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        if ($charters->isEmpty()) {
            return [];
        }

        // The current version for the whole set in one fetch —
        // currentVersion() runs a per-row query, unusable in a list.
        $versions = SessionZeroSurveyVersion::query()
            ->whereIn('session_zero_survey_id', $charters->pluck('id'))
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
                'campaign_name' => (string) $charter->campaign?->name,
                'charter_uuid' => (string) $charter->uuid,
                'status' => (string) $charter->status,
                'current_version' => $charter->current_version,
                'approvedCount' => $approved,
                'signedCount' => $signed,
                'pendingCount' => $pending,
                'percentSigned' => $percent,
                'daysOutstanding' => $daysOutstanding,
                'fullySigned' => $version !== null && ($countersignedVersions[(string) $version->id] ?? 0) > 0,
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

        $rows = $charter->confirmations()
            ->where('session_zero_survey_version_id', $version->id)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->get();

        // Fallback candidates: every earlier-version participant row that
        // actually submitted answers, from users not covered by a row on
        // the requested version (when nobody is covered, every earlier
        // submitter is a candidate — whereNotIn with an empty list would
        // match nothing). Newest-first per user — highest version_number
        // wins, latest confirmed_at breaks (theoretical) ties, the same
        // ordering amend() uses for carries.
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
        $version = null;
        if ($charter->isCharter() && $charter->isActive()) {
            $version = $charter->currentVersion();
        }

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
