<?php

namespace App\Livewire\Reviews;

use App\Enums\NotificationCategory;
use App\Models\Review;
use App\Notifications\ReviewReported;
use App\Services\NotificationService;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReportReview extends Component
{
    #[Locked]
    public string $reviewId;

    public bool $showModal = false;

    public ?string $reason = null;

    public ?string $successMessage = null;

    public function mount(string $reviewId): void
    {
        $this->reviewId = $reviewId;
    }

    public function openModal(): void
    {
        $this->reset('reason', 'successMessage');
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset('reason', 'successMessage');
    }

    public function submitReport(): void
    {
        $this->validate();

        $review = Review::find($this->reviewId);

        if (! $review) {
            $this->addError('reason', __('reviews.error_review_not_found'));
            return;
        }

        Gate::authorize('report', $review);

        if ($review->isReported()) {
            $this->addError('reason', __('reviews.error_already_reported'));
            return;
        }

        $reporter = Auth::user();

        $review->report($reporter->id, $this->reason);

        Log::info('review.reported', [
            'review_id' => $review->id,
            'reviewable_type' => $review->reviewable_type,
            'reviewable_id' => $review->reviewable_id,
            'reporter_id' => $reporter->id,
            'reason' => $this->reason,
        ]);

        // Notify all global admins
        $this->notifyAdmins($review, $reporter);

        $this->successMessage = __('reviews.flash_review_reported');
        $this->showModal = false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:inappropriate,spam,harassment,other'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => __('reviews.validation_report_reason_required'),
            'reason.in' => __('reviews.validation_report_reason_invalid'),
        ];
    }

    public function render()
    {
        return view('livewire.reviews.report-review');
    }

    /**
     * Notify all global admins about the reported review.
     */
    private function notifyAdmins(Review $review, $reporter): void
    {
        $adminService = app(ScopedRoleService::class);
        $notificationService = app(NotificationService::class);

        $admins = \App\Models\User::all()->filter(
            fn ($user) => $adminService->isGlobalAdmin($user)
        );

        foreach ($admins as $admin) {
            $notificationService->send(
                $admin,
                new ReviewReported($review, $reporter),
                NotificationCategory::ReviewReported,
            );
        }
    }
}
