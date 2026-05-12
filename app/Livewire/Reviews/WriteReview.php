<?php

namespace App\Livewire\Reviews;

use App\Enums\GmProficiency;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class WriteReview extends Component
{
    #[\Livewire\Attributes\Locked]
    public string $reviewableType;

    #[\Livewire\Attributes\Locked]
    public string $reviewableId;

    public int $rating = 0;
    public string $body = '';
    public array $proficiency_tags = [];

    public ?string $reviewableName = null;
    public ?string $errorMessage = null;

    public function mount(string $reviewable_type, string $reviewable_id): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'));
            return;
        }

        $this->reviewableType = $reviewable_type;
        $this->reviewableId = $reviewable_id;

        $reviewable = $this->resolveReviewable();

        if (! $reviewable) {
            $this->errorMessage = __('reviews.error_not_found');
            return;
        }

        $this->reviewableName = $reviewable->name;

        if ($reviewable instanceof Game) {
            if (! Gate::allows('canReviewSession', [Review::class, $reviewable])) {
                $this->errorMessage = __('reviews.error_not_eligible');
                return;
            }
        } elseif ($reviewable instanceof Campaign) {
            if (! Gate::allows('canReviewCampaign', [Review::class, $reviewable])) {
                $this->errorMessage = __('reviews.error_not_eligible');
                return;
            }
        }

        Gate::authorize('create', Review::class);
    }

    public function submit(): void
    {
        if ($this->errorMessage) {
            return;
        }

        $this->validate();

        $reviewable = $this->resolveReviewable();
        if (! $reviewable) {
            return;
        }

        // Re-verify eligibility at submit time (not just mount) to prevent TOCTOU attacks
        if ($reviewable instanceof Game) {
            if (! Gate::allows('canReviewSession', [Review::class, $reviewable])) {
                $this->errorMessage = __('reviews.error_not_eligible');
                return;
            }
        } elseif ($reviewable instanceof Campaign) {
            if (! Gate::allows('canReviewCampaign', [Review::class, $reviewable])) {
                $this->errorMessage = __('reviews.error_not_eligible');
                return;
            }
        }

        Gate::authorize('create', Review::class);

        $gmProfile = $this->resolveGmProfile($reviewable);

        Review::create([
            'reviewable_type' => $this->reviewableType,
            'reviewable_id' => $this->reviewableId,
            'reviewer_id' => Auth::id(),
            'gm_profile_id' => $gmProfile?->id,
            'rating' => $this->rating,
            'body' => $this->body ?: null,
            'proficiency_tags' => ! empty($this->proficiency_tags) ? $this->proficiency_tags : null,
            'status' => 'published',
        ]);

        $redirectRoute = $reviewable instanceof Game
            ? route('games.show', $reviewable->id)
            : route('campaigns.show', $reviewable->id);

        session()->flash('success', __('reviews.flash_review_submitted'));

        $this->redirect($redirectRoute, navigate: true);
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
            'proficiency_tags' => ['nullable', 'array', 'max:3'],
            'proficiency_tags.*' => ['string', Rule::in(GmProficiency::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => __('reviews.validation_rating_required'),
            'rating.min' => __('reviews.validation_rating_min'),
            'rating.max' => __('reviews.validation_rating_max'),
            'body.max' => __('reviews.validation_body_max'),
            'proficiency_tags.max' => __('reviews.validation_tags_max'),
            'proficiency_tags.*.in' => __('reviews.validation_tags_invalid'),
        ];
    }

    public function toggleTag(string $tag): void
    {
        if (in_array($tag, $this->proficiency_tags)) {
            $this->proficiency_tags = array_values(array_diff($this->proficiency_tags, [$tag]));
        } else {
            if (count($this->proficiency_tags) >= 3) {
                $this->addError('proficiency_tags', __('reviews.validation_tags_max'));
                return;
            }
            $this->proficiency_tags[] = $tag;
        }

        $this->resetErrorBag('proficiency_tags');
    }

    public function render()
    {
        return view('livewire.reviews.write-review', [
            'proficiencies' => GmProficiency::cases(),
            'errorMessage' => $this->errorMessage,
        ])->layout('layouts.app');
    }

    // ── Helpers ─────────────────────────────────────────

    private function resolveReviewable(): ?object
    {
        $class = match ($this->reviewableType) {
            'game', Game::class => Game::class,
            'campaign', Campaign::class => Campaign::class,
            default => null,
        };

        if (! $class) {
            return null;
        }

        // Normalize to FQCN for storage
        $this->reviewableType = $class;

        return $class::find($this->reviewableId);
    }

    private function resolveGmProfile(object $reviewable): ?GMProfile
    {
        return $reviewable->owner?->gmProfile;
    }
}
