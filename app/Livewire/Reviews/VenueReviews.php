<?php

namespace App\Livewire\Reviews;

use App\Models\Location;
use App\Models\Review;
use App\Services\LocationDisclosureService;
use App\Services\ReviewEligibilityService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Venue reviews surface (M053/S03): aggregate summary + review list + an
 * attended-only write affordance, mounted into the S02 venue page reviews
 * section.
 *
 * This is a nested (inline) Livewire component — it renders inside
 * VenueDetail's full-page layout, so render() returns a bare view (no
 * ->layout()).
 *
 * Write eligibility is the {@see ReviewPolicy::canReviewVenue()} gate, which
 * delegates to {@see ReviewEligibilityService::canReviewVenue()}
 * — attended-only (approved participant of a completed session at the venue)
 * and gated first on the S02 {@see LocationDisclosureService::
 * isPublicVenuePage()} single authority. A non-attendee never sees the form,
 * and a direct submit() is rejected via the same gate (re-checked at submit
 * time for TOCTOU safety, mirroring WriteReview).
 *
 * Venue reviews are polymorphic: reviewable_type = App\Models\Location,
 * gm_profile_id = null, proficiency_tags = null. Creating a Review fires
 * ReviewObserver::created(), which recomputes the venue aggregate columns
 * (T03) — so the header updates automatically after a successful submit.
 */
class VenueReviews extends Component
{
    /** The venue this surface reviews. Locked so it can't be tampered from the client. */
    #[Locked]
    public Location $location;

    public int $rating = 0;

    public string $body = '';

    public ?string $errorMessage = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'rating.required' => __('venue.validation_venue_rating_required'),
            'rating.min' => __('reviews.validation_rating_min'),
            'rating.max' => __('reviews.validation_rating_max'),
            'body.max' => __('venue.validation_venue_body_max'),
        ];
    }

    public function submit(): void
    {
        if ($this->errorMessage) {
            return;
        }

        $this->validate();

        // Re-verify eligibility at submit time (not just render) to prevent
        // TOCTOU attacks — mirrors WriteReview::submit().
        if (! Gate::allows('canReviewVenue', [Review::class, $this->location])) {
            $this->errorMessage = __('venue.content_not_eligible');

            return;
        }

        Gate::authorize('create', Review::class);

        Review::create([
            'reviewable_type' => Location::class,
            'reviewable_id' => $this->location->id,
            'reviewer_id' => Auth::id(),
            'gm_profile_id' => null,
            'proficiency_tags' => null,
            'rating' => $this->rating,
            'body' => $this->body ?: null,
            'status' => 'published',
        ]);

        // ReviewObserver::created() recomputed the aggregate columns on the
        // DB row; refresh the in-memory model so the header renders fresh.
        $this->location->refresh();

        session()->flash('success', __('venue.flash_venue_review_submitted'));

        $this->reset(['rating', 'body']);
    }

    public function render(): View
    {
        $reviews = $this->location->reviews()
            ->published()
            ->with('reviewer')
            ->latest()
            ->limit(50)
            ->get();

        // Auth::check() short-circuits the gate so guests never trigger a
        // policy resolution with no authenticated user.
        $canReview = Auth::check()
            && Gate::allows('canReviewVenue', [Review::class, $this->location]);

        return view('livewire.reviews.venue-reviews', compact('reviews', 'canReview'));
    }
}
