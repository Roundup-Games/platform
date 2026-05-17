<?php

namespace App\Livewire\Reviews;

use App\Enums\NotificationCategory;
use App\Models\Review;
use App\Models\User;
use App\Notifications\ReviewReported;
use App\Services\NotificationService;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        $reporter = Auth::user();

        // Rate limit: 5 review reports per user per hour
        $rateLimitKey = "review-reports:{$reporter->id}";
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, decaySeconds: 3600)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = ceil($seconds / 60);
            $this->addError('reason', __('reports.error_rate_limit', ['minutes' => $minutes]));

            return;
        }

        // Atomically check for existing report and create ticket to prevent race conditions
        $duplicateDetected = false;

        DB::transaction(function () use ($review, $reporter, &$duplicateDetected) {
            // Re-check inside transaction with exclusive lock
            $lockedReview = Review::where('id', $review->id)->lockForUpdate()->first();

            if ($lockedReview && $lockedReview->isReported()) {
                $duplicateDetected = true;

                return;
            }

            $review->report($reporter->id, $this->reason);

            Log::info('review.reported', [
                'review_id' => $review->id,
                'reviewable_type' => $review->reviewable_type,
                'reviewable_id' => $review->reviewable_id,
                'reporter_id' => $reporter->id,
                'reason' => $this->reason,
            ]);

            // Create Escalated safety ticket
            $this->createSafetyTicket($review, $reporter);
        });

        if ($duplicateDetected) {
            $this->addError('reason', __('reviews.error_already_reported'));

            return;
        }

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
     * Create an Escalated ticket in the Safety department for the reported review.
     */
    private function createSafetyTicket(Review $review, User $reporter): void
    {
        $department = Department::where('name', 'Safety')->first();

        $metadata = [
            'review_id' => $review->id,
            'review_author_id' => $review->reviewer_id,
            'report_reason' => $this->reason,
            'reporter_id' => $reporter->id,
        ];

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $reporter->id,
            'subject' => 'Review Report: ' . ucfirst($this->reason ?? 'other'),
            'description' => $this->buildTicketDescription($review, $reporter),
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::High->value,
            'department_id' => $department?->id,
            'ticket_type' => 'review_report',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        // Apply review-report tag
        $tag = Tag::where('name', 'review-report')->first();
        if ($tag) {
            $ticket->tags()->syncWithoutDetaching([$tag->id]);
        }

        Log::info('review.report.ticket_created', [
            'review_id' => $review->id,
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'department' => $department?->name,
        ]);
    }

    /**
     * Build a human-readable description for the safety ticket.
     */
    private function buildTicketDescription(Review $review, User $reporter): string
    {
        $reviewAuthor = $review->reviewer;
        $lines = [
            "**Reported by:** {$reporter->name}",
            "**Reason:** " . ucfirst($this->reason ?? 'other'),
            '',
            '**Review details:**',
            "- Rating: {$review->rating}/5",
            '- Author: ' . ($reviewAuthor?->name ?? 'Unknown'),
            '- Content: ' . ($review->body ?? '(no content)'),
            '',
            "**Review ID:** {$review->id}",
        ];

        return implode("\n", $lines);
    }

    /**
     * Notify all global admins about the reported review.
     */
    private function notifyAdmins(Review $review, $reporter): void
    {
        $notificationService = app(NotificationService::class);

        // Query only Platform Admin users instead of loading all users
        $admins = User::role('Platform Admin')->get();

        foreach ($admins as $admin) {
            $notificationService->send(
                $admin,
                new ReviewReported($review, $reporter),
                NotificationCategory::ReviewReported,
            );
        }
    }
}
