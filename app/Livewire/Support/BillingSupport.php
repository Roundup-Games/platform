<?php

namespace App\Livewire\Support;

use App\Models\User;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Billing support ticket form for payment and subscription issues.
 *
 * Creates an Escalated ticket in the Billing department with subscription metadata.
 * Rate limited to 5 submissions per user per hour.
 */
#[Layout('layouts.app')]
class BillingSupport extends Component
{
    public string $subject = '';

    public string $description = '';

    public string $issueType = 'payment_issue';

    public ?string $successMessage = null;

    /** Supported billing issue types. */
    private const ISSUE_TYPES = [
        'payment_issue' => 'support.billing_issue_payment',
        'refund_request' => 'support.billing_issue_refund',
        'subscription_change' => 'support.billing_issue_subscription_change',
        'invoice_question' => 'support.billing_issue_invoice',
        'cancellation_issue' => 'support.billing_issue_cancellation',
        'other' => 'support.billing_issue_other',
    ];

    public function submitBillingSupport(): void
    {
        $this->validate();

        $user = Auth::user();

        // Rate limit: 5 billing support tickets per user per hour
        $rateLimitKey = "billing-support:{$user->id}";

        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, decaySeconds: 3600)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = ceil($seconds / 60);
            $this->addError('subject', __('support.error_rate_limit', ['minutes' => $minutes]));

            return;
        }

        $this->createBillingTicket($user);

        Log::info('support.billing_ticket_created', [
            'user_id' => $user->id,
            'issue_type' => $this->issueType,
            'department' => 'Billing',
        ]);

        $this->successMessage = __('support.flash_billing_ticket_submitted');
        $this->reset('subject', 'description', 'issueType');
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'issueType' => ['required', 'string', 'in:' . implode(',', array_keys(self::ISSUE_TYPES))],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => __('support.validation_subject_required'),
            'description.required' => __('support.validation_description_required'),
            'description.max' => __('support.validation_description_max'),
            'issueType.required' => __('support.validation_issue_type_required'),
            'issueType.in' => __('support.validation_issue_type_invalid'),
        ];
    }

    /**
     * Get the issue types for the view.
     */
    public function getIssueTypes(): array
    {
        return collect(self::ISSUE_TYPES)->mapWithKeys(fn ($label, $key) => [
            $key => __($label),
        ])->toArray();
    }

    public function render()
    {
        return view('livewire.support.billing-support', [
            'issueTypes' => $this->getIssueTypes(),
        ]);
    }

    /**
     * Create an Escalated ticket in the Billing department with subscription metadata.
     */
    private function createBillingTicket(User $user): void
    {
        $department = Department::where('name', 'Billing')->first();

        // Gather billing metadata
        $subscription = $user->subscription();
        $metadata = [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'issue_type' => $this->issueType,
            'has_subscription' => $subscription !== null,
            'subscription_status' => $subscription?->status,
            'paddle_subscription_id' => $subscription?->paddle_id,
            'paddle_customer_id' => $user->paddle_id,
        ];

        // Determine priority based on issue type
        $priority = match ($this->issueType) {
            'payment_issue' => TicketPriority::High->value,
            'refund_request' => TicketPriority::High->value,
            'cancellation_issue' => TicketPriority::Medium->value,
            default => TicketPriority::Medium->value,
        };

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => $this->subject,
            'description' => $this->buildTicketDescription($user, $subscription),
            'status' => TicketStatus::Open->value,
            'priority' => $priority,
            'department_id' => $department?->id,
            'ticket_type' => 'billing_support',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        // Apply billing-support tag
        $tag = Tag::where('name', 'billing-support')->first();
        if ($tag) {
            $ticket->tags()->syncWithoutDetaching([$tag->id]);
        }

        Log::info('support.billing_ticket_created', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'user_id' => $user->id,
            'issue_type' => $this->issueType,
            'paddle_subscription_id' => $subscription?->paddle_id,
        ]);
    }

    /**
     * Build a human-readable description for the billing support ticket.
     */
    private function buildTicketDescription(User $user, $subscription): string
    {
        $issueLabel = __(self::ISSUE_TYPES[$this->issueType] ?? $this->issueType);

        $lines = [
            "**Submitted by:** {$user->name} ({$user->email})",
            "**Issue type:** {$issueLabel}",
        ];

        if ($subscription) {
            $lines[] = '';
            $lines[] = '**Subscription details:**';
            $lines[] = "- Status: " . ucfirst($subscription->status);
            if ($subscription->paddle_id) {
                $lines[] = "- Paddle Subscription ID: {$subscription->paddle_id}";
            }
            if ($subscription->type) {
                $lines[] = "- Plan: " . ucfirst($subscription->type);
            }
        }

        $lines[] = '';
        $lines[] = '**Details:**';
        $lines[] = $this->description;

        return implode("\n", $lines);
    }
}
