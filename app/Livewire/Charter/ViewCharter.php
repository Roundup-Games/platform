<?php

namespace App\Livewire\Charter;

use App\Dto\CharterContent;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\Location;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Charter\CharterSigningService;
use App\Support\CharterDiff;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only published campaign charter (M070/S01-T05).
 *
 * Public share surface at /charter/{uuid} — the same UUID
 * capability-link trust model as session-zero.view: knowing the link
 * grants read access. Renders the immutable currentVersion() snapshot
 * (never mutable row content; the row fallback is defensive only for a
 * dangling version pointer), normalized through CharterContent so old
 * or partial snapshots keep rendering tolerantly (concept §6).
 *
 * Access by lifecycle state:
 *  - draft    → 404 for everyone except the campaign owner, who gets a
 *               draft-banner preview (guests probing the link cannot
 *               distinguish a draft from an unknown UUID)
 *  - active   → public reading view; the campaign owner additionally
 *               sees the GM strip (published vN + date + copy link)
 *  - archived → public reading view with an archived note
 *  - kind !== 'charter' (legacy surveys) → 404 — those keep rendering
 *    untouched at their own /session-zero/{uuid} surface
 *
 * Signing layer (M070/S02-T03, concept §4): approved participants of an
 * active charter get the auth-aware signing experience on this same
 * page — §2 private boundary answers inline at the boundaries section
 * and the five-attestation signature panel below the agreement section.
 * The component stays thin (.ai/rules/livewire.md): eligibility reads go
 * through CharterSigningService::statusFor, and the sign() mutation
 * delegates wholesale to CharterSigningService::sign. Only the viewer's
 * own signature is ever loaded here — other participants' answers stay
 * behind the GM-only choke point (privateAnswersFor). Guests,
 * non-participants, pending participants, and draft/archived viewers see
 * the exact S01 read-only markup.
 *
 * GM layer (M070/S02-T04, concept §4/§8): the campaign owner additionally
 * gets the signature roster (rows via CharterSigningService::roster, §2
 * private answers via the owner-gated privateAnswersFor — never a direct
 * confirmation query in the view) and the countersign() action (wholesale
 * delegation to CharterSigningService::countersign). The role='gm'
 * countersignature on the current version is loaded for EVERY viewer so
 * the agreement section can render the §8 countersignature line to
 * everyone once present.
 */
#[Layout('layouts.app')]
class ViewCharter extends Component
{
    #[Locked]
    public string $uuid;

    /**
     * The five §8 attestation checkboxes, keyed by
     * CharterContent::AGREEMENT_ITEMS; the labels render the S01
     * charter.confirm_* copy verbatim (locked convention).
     *
     * @var array<string, bool>
     */
    public array $attestations = [];

    /** @var array<int, string> */
    public array $myLinesCategories = [];

    /** @var array<int, string> */
    public array $myVeilsCategories = [];

    public string $myLinesText = '';

    public string $myVeilsText = '';

    public string $noteToGm = '';

    private ?SessionZeroSurvey $resolvedCharter = null;

    private ?SessionZeroConfirmation $viewerSignature = null;

    private bool $viewerSignatureResolved = false;

    /** Memoized like viewerSignature: render data and amendedBanner() both ask. */
    private bool $canSign = false;

    private bool $canSignResolved = false;

    /** @var array{amendedOn: CarbonInterface, changelog: string, changes: list<array<string, mixed>>}|null */
    private ?array $amendedBanner = null;

    private bool $amendedBannerResolved = false;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        $charter = $this->resolveCharter();

        if ($charter === null || ! $charter->isCharter()) {
            abort(404);
        }

        if ($charter->isDraft() && ! $this->isOwner()) {
            abort(404);
        }

        // Re-agreement continuity (concept §3): a participant facing the
        // signing form starts from the answers they previously submitted
        // — re-confirmation after a material amendment must never
        // degrade into re-entry from memory.
        $this->prefillPriorAnswers();
    }

    public function render(): View
    {
        $charter = $this->resolveCharter();

        $version = $charter !== null && ! $charter->isDraft()
            ? $charter->currentVersion()
            : null;

        $content = $this->renderContent($charter, $version);

        $logistics = $content['logistics'] ?? null;
        $locationId = is_array($logistics) ? ($logistics['location_id'] ?? null) : null;

        // §8 GM countersignature on the current version, loaded for every
        // viewer: the agreement section renders the countersignature line
        // to everyone once present (S02-T04). The row carries no private
        // content (countersigns store private_answers = null).
        $gmCountersignature = $charter !== null && $version !== null
            ? $charter->confirmations()
                ->where('session_zero_survey_version_id', $version->id)
                ->where('role', SessionZeroConfirmation::ROLE_GM)
                ->with('user')
                ->first()
            : null;

        // GM roster (S02-T04): approved participants paired with their
        // confirmations via the service, and their §2 private answers via
        // the owner-gated choke point — never a direct confirmation query.
        // Owner-only and active-only (drafts have no version; archived
        // charters can no longer be countersigned).
        /** @var Collection<int, array{participant: CampaignParticipant, user: User|null, confirmation: SessionZeroConfirmation|null, reagreementPending: bool}> $roster */
        $roster = collect();
        /** @var Collection<string, SessionZeroConfirmation> $rosterAnswers */
        $rosterAnswers = collect();
        $rosterSigned = 0;
        $rosterTotal = 0;

        $owner = Auth::user();
        if ($charter !== null && $version !== null && $owner !== null
            && $charter->isActive() && $this->isOwner()) {
            $roster = CharterSigningService::roster($charter);
            $rosterAnswers = CharterSigningService::privateAnswersFor($charter, $version, $owner)
                ->keyBy('user_id');
            $rosterTotal = $roster->count();
            $rosterSigned = $roster->filter(
                static fn (array $row): bool => $row['confirmation'] !== null
            )->count();
        }

        return view('livewire.charter.view-charter', [
            'charter' => $charter,
            'isOwner' => $this->isOwner(),
            'version' => $version,
            'content' => $content,
            'location' => is_string($locationId) && $locationId !== '' ? Location::find($locationId) : null,
            'canSign' => $this->canSign(),
            'mySignature' => $this->viewerSignature(),
            'amendedBanner' => $this->amendedBanner(),
            'gmCountersignature' => $gmCountersignature,
            'roster' => $roster,
            'rosterAnswers' => $rosterAnswers,
            'rosterSigned' => $rosterSigned,
            'rosterTotal' => $rosterTotal,
        ]);
    }

    // ── Signing (concept §4) ─────────────────────────────

    /**
     * Record the viewer's signature. Thin delegation per
     * .ai/rules/livewire.md: every guard and the mutation itself live in
     * CharterSigningService::sign (active published charter, approved
     * participant, not-yet-signed, attestation and text validation,
     * race-safe insert). The service's ValidationException propagates
     * into Livewire's validation hook, which loads its messages into the
     * component error bag for display; after a successful sign the next
     * render finds the row via statusFor() and flips to the signed-state
     * card.
     */
    public function sign(): void
    {
        $charter = $this->resolveCharter();
        $user = Auth::user();

        if ($charter === null || $user === null) {
            // Defensive only: the panel is never rendered for guests, so
            // this branch is reachable solely by a crafted request.
            throw ValidationException::withMessages([
                'charter' => __('charter.error_not_participant'),
            ]);
        }

        CharterSigningService::sign($user, $charter, [
            'attestations' => $this->attestations,
            'private_answers' => [
                'my_lines_categories' => $this->myLinesCategories,
                'my_veils_categories' => $this->myVeilsCategories,
                'my_lines_text' => $this->myLinesText,
                'my_veils_text' => $this->myVeilsText,
                'note_to_gm' => $this->noteToGm,
            ],
        ]);

        session()->flash('charter_signed', true);
    }

    /**
     * Countersign the current version (concept §8). Thin delegation per
     * .ai/rules/livewire.md: the owner gate, the everyone-signed
     * requirement, and the race-safe insert live in
     * CharterSigningService::countersign; its ValidationException lands in
     * this component's error bag for display. After a successful
     * countersign the next render finds the role='gm' row and swaps the
     * button for the countersigned state.
     */
    public function countersign(): void
    {
        $charter = $this->resolveCharter();
        $user = Auth::user();

        if ($charter === null || $user === null) {
            // Defensive only: the control is never rendered for non-owners,
            // so this branch is reachable solely by a crafted request.
            throw ValidationException::withMessages([
                'charter' => __('charter.error_not_owner'),
            ]);
        }

        CharterSigningService::countersign($user, $charter);

        session()->flash('charter_countersigned', true);
    }

    // ── Internals ────────────────────────────────────────

    /**
     * Seed the signing form's private-answer fields from the viewer's
     * OWN latest submission across versions (the latestParticipantConfirmation
     * contract — same ordering as signature carries). Mount-time only:
     * never re-run on later requests, where it would clobber the user's
     * in-progress edits. Their own answers, never another participant's.
     */
    private function prefillPriorAnswers(): void
    {
        $charter = $this->resolveCharter();
        $user = Auth::user();

        if ($charter === null || $user === null || ! $this->canSign()) {
            return;
        }

        $prior = CharterSigningService::latestParticipantConfirmation($charter, $user, withAnswersOnly: true);

        if ($prior === null) {
            return;
        }

        $answers = $prior->private_answers ?? [];

        $lines = $answers['my_lines_categories'] ?? [];
        $veils = $answers['my_veils_categories'] ?? [];

        $this->myLinesCategories = is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
        $this->myVeilsCategories = is_array($veils) ? array_values(array_filter($veils, 'is_string')) : [];
        $this->myLinesText = self::prefillText($answers['my_lines_text'] ?? null);
        $this->myVeilsText = self::prefillText($answers['my_veils_text'] ?? null);
        $this->noteToGm = self::prefillText($answers['note_to_gm'] ?? null);
    }

    private static function prefillText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function resolveCharter(): ?SessionZeroSurvey
    {
        if ($this->resolvedCharter === null) {
            $this->resolvedCharter = SessionZeroSurvey::findByUuid($this->uuid);
        }

        return $this->resolvedCharter;
    }

    /**
     * Campaign-owner gate (R084: ownership is the campaign owner_id,
     * never a GM membership or role check).
     */
    private function isOwner(): bool
    {
        $user = Auth::user();
        $ownerId = $this->resolveCharter()?->campaign?->owner_id;

        return $user !== null
            && $ownerId !== null
            && (string) $ownerId === (string) $user->id;
    }

    /**
     * Signing eligibility (concept §4): authenticated viewer, approved
     * participant of the charter's campaign, charter active and
     * published, and no signature on the current version yet. Everyone
     * else keeps the read-only S01 rendering.
     */
    private function canSign(): bool
    {
        if ($this->canSignResolved) {
            return $this->canSign;
        }

        $charter = $this->resolveCharter();
        $user = Auth::user();

        if ($charter === null || $user === null || ! $charter->isCharter() || ! $charter->isActive()) {
            return false;
        }

        if ($this->viewerSignature() !== null) {
            return false;
        }

        $this->canSign = CampaignParticipant::query()
            ->where('campaign_id', $charter->campaign_id)
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
        $this->canSignResolved = true;

        return $this->canSign;
    }

    /**
     * The viewer's own signature on the current version (null = not
     * signed yet). Only the viewer's row is ever loaded in this
     * component — other participants' answers stay behind the GM-only
     * choke point.
     */
    private function viewerSignature(): ?SessionZeroConfirmation
    {
        if (! $this->viewerSignatureResolved) {
            $this->viewerSignatureResolved = true;

            $charter = $this->resolveCharter();
            $user = Auth::user();

            $this->viewerSignature = $charter !== null && $user !== null && $charter->isCharter() && $charter->isActive()
                ? CharterSigningService::statusFor($charter, $user)
                : null;
        }

        return $this->viewerSignature;
    }

    /**
     * Viewer-scoped amended banner (M070/S03-T04): an approved participant
     * of an active charter whose latest participant confirmation across
     * earlier versions sits in pending re-agreement — exactly the state a
     * material amendment leaves behind — gets the amended banner with the
     * amended-on date, the current version's changelog, and the
     * field-level diff against the version their pending row sits on.
     * Everyone else sees nothing: guests and non-participants fail canSign,
     * draft/archived charters are not active, and viewers signed on the
     * current version (freshly re-signed or carried by a minor amendment)
     * are caught by the statusFor check inside canSign — the banner state
     * is always the viewer's own, never another participant's.
     *
     * @return array{amendedOn: CarbonInterface, changelog: string, changes: list<array<string, mixed>>}|null
     */
    private function amendedBanner(): ?array
    {
        if ($this->amendedBannerResolved) {
            return $this->amendedBanner;
        }
        $this->amendedBannerResolved = true;
        $this->amendedBanner = null;

        $charter = $this->resolveCharter();
        $user = Auth::user();
        $version = $charter?->currentVersion();

        if ($charter === null || $user === null || $version === null || ! $this->canSign()) {
            return null;
        }

        // Their latest participant confirmation across earlier versions
        // (the latestParticipantConfirmation ordering contract). No row
        // at all means they never signed: the plain signing panel is the
        // right surface, not the banner.
        $confirmation = CharterSigningService::latestParticipantConfirmation($charter, $user);

        if ($confirmation === null
            || $confirmation->status !== SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT) {
            return null;
        }

        // The diff baseline is the version the pending row sits on; the
        // relation is only null on a dangling version pointer, which the
        // append-only schema rules out — current-minus-one is the defensive
        // fallback.
        $signedVersion = $confirmation->version
            ?? $charter->versions()
                ->where('version_number', $version->version_number - 1)
                ->first();

        $this->amendedBanner = [
            'amendedOn' => $version->published_at,
            'changelog' => (string) ($version->changelog ?? ''),
            // Venue uuids resolve to names — the same resolution the
            // GM's review-step preview applies (CharterDiff::
            // resolveLocationNames), so participants never see a raw
            // row identifier on the public charter page.
            'changes' => $signedVersion !== null
                ? CharterDiff::resolveLocationNames(CharterDiff::changes($signedVersion->content, $version->content))
                : [],
        ];

        return $this->amendedBanner;
    }

    /**
     * Tolerant normalized render content: the published version snapshot
     * (row content only when the version pointer dangles — defensive),
     * or the draft row while drafting. fromArray drops unknown keys and
     * fills schema defaults so old snapshots keep rendering (§6).
     *
     * @return array<string, mixed>
     */
    private function renderContent(?SessionZeroSurvey $charter, ?SessionZeroSurveyVersion $version): array
    {
        $raw = match (true) {
            $version !== null => $version->content,
            $charter !== null => $charter->content,
            default => [],
        };

        return CharterContent::fromArray($raw)->toArray();
    }
}
