<?php

namespace App\Livewire\Venues;

use App\Models\Location;
use App\Services\LocationDisclosureService;
use App\Services\VenueClaimService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public "claim this venue" form at /{locale}/venue/{slug}/claim (M053/S04/T04).
 *
 * A venue operator files a claim to take over stewardship of an existing public
 * venue page. Structurally mirrors {@see ProposeVenue} (same Layout, same
 * validate → duplicate-guard → rate-limit → service-create → success-state
 * flow) but submits through {@see VenueClaimService} against an existing
 * Location instead of creating a new one.
 *
 * The 404 gate reuses the single named authority,
 * {@see LocationDisclosureService::isPublicVenuePage()}, so the "who gets a
 * page" rule cannot drift from {@see VenueDetail}'s gate: a venue that has no
 * public page cannot have a claim form either.
 *
 * Privacy: the form collects a justification (→ claimant_notes), an optional
 * website, and an optional contact email as proof of affiliation. No address
 * material is ever requested or submitted — a claim is about stewardship, not
 * address disclosure (MEM717).
 */
#[Layout('layouts.app')]
class ClaimVenue extends Component
{
    public Location $location;

    // ── Form Properties ──────────────────────────────

    /** Required: the operator's reason for claiming the venue. */
    public string $justification = '';

    /** Optional proof: the venue's (or operator's) website. */
    public ?string $website_url = null;

    /** Optional proof: a contact email for the review team to verify affiliation. */
    public ?string $contact_email = null;

    public bool $submitted = false;

    public ?string $ticketReference = null;

    // ── Lifecycle ────────────────────────────────────

    public function mount(string $slug): void
    {
        $location = Location::where('slug', $slug)->firstOrFail();

        // MEM717 + single authority: only public venue pages are claimable.
        // abort_unless keeps the failure path a standard 404 so a non-public
        // location is never reachable from this route — exactly like VenueDetail.
        abort_unless(
            app(LocationDisclosureService::class)->isPublicVenuePage($location),
            404,
        );

        $this->location = $location;
    }

    // ── Validation ───────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'justification' => 'required|string|max:1000',
            'website_url' => 'nullable|url|max:500',
            'contact_email' => 'nullable|email|max:255',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationAttributes(): array
    {
        return [
            'justification' => __('venue.field_justification'),
            'website_url' => __('venue.field_website'),
            'contact_email' => __('venue.field_contact_email'),
        ];
    }

    // ── Actions ──────────────────────────────────────

    public function submit(VenueClaimService $service): void
    {
        $this->validate();

        $user = authenticatedUser();

        // One pending claim per user per venue (mirrors hasPendingProposal).
        if ($service->hasPendingClaim($user, $this->location)) {
            $this->addError('justification', __('venue.error_claim_duplicate'));

            return;
        }

        // Rate limit: 3 claims/day per user (mirrors the propose-venue limiter).
        $rateLimitKey = 'venue-claim:'.$user->id;

        if (! RateLimiter::attempt($rateLimitKey, 3, fn () => true, decaySeconds: 86400)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $hours = ceil($seconds / 3600);
            $this->addError('justification', __('venue.error_claim_rate_limit', ['hours' => $hours]));

            return;
        }

        $data = [
            'claimant_notes' => trim($this->justification),
            'website_url' => $this->website_url ? trim($this->website_url) : null,
            'contact_email' => $this->contact_email ? trim($this->contact_email) : null,
        ];

        try {
            $ticket = $service->createClaim($user, $this->location, $data);
        } catch (\RuntimeException $e) {
            report($e);
            $this->addError('justification', __('venue.error_claim_submission_failed'));

            return;
        }

        $this->ticketReference = $ticket->reference;
        $this->reset(['justification', 'website_url', 'contact_email']);
        $this->submitted = true;

        session()->flash('success', __('venue.content_claim_success'));
    }

    // ── Render ───────────────────────────────────────

    public function render(): View
    {
        return view('livewire.venues.claim-venue', [
            'location' => $this->location,
        ]);
    }
}
