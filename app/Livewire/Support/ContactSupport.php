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
 * Authenticated support ticket form for account recovery and account-related issues.
 *
 * Creates an Escalated ticket in the Account Support department.
 * Rate limited to 5 submissions per user per hour.
 */
#[Layout('layouts.app')]
class ContactSupport extends Component
{
    public string $subject = '';

    public string $description = '';

    public string $issueType = 'account_access';

    public ?string $successMessage = null;

    /** Supported issue types for account support. */
    private const ISSUE_TYPES = [
        'account_access' => 'support.issue_account_access',
        'login_issue' => 'support.issue_login_issue',
        'name_change' => 'support.issue_name_change',
        'email_change' => 'support.issue_email_change',
        'suspended_account' => 'support.issue_suspended_account',
        'data_request' => 'support.issue_data_request',
        'other' => 'support.issue_other',
    ];

    public function submitSupport(): void
    {
        $this->validate();

        $user = Auth::user();

        // Rate limit: 5 support tickets per user per hour
        $rateLimitKey = "account-support:{$user->id}";

        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, decaySeconds: 3600)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = ceil($seconds / 60);
            $this->addError('subject', __('support.error_rate_limit', ['minutes' => $minutes]));

            return;
        }

        $this->createAccountSupportTicket($user);

        Log::info('support.ticket_created', [
            'user_id' => $user->id,
            'issue_type' => $this->issueType,
            'department' => 'Account Support',
        ]);

        $this->successMessage = __('support.flash_ticket_submitted');
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
        return view('livewire.support.contact-support', [
            'issueTypes' => $this->getIssueTypes(),
        ]);
    }

    /**
     * Create an Escalated ticket in the Account Support department.
     */
    private function createAccountSupportTicket(User $user): void
    {
        $department = Department::where('name', 'Account Support')->first();

        $metadata = [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'issue_type' => $this->issueType,
        ];

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => $this->subject,
            'description' => $this->buildTicketDescription($user),
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Medium->value,
            'department_id' => $department?->id,
            'ticket_type' => 'account_recovery',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        // Apply account-recovery tag
        $tag = Tag::where('name', 'account-recovery')->first();
        if ($tag) {
            $ticket->tags()->syncWithoutDetaching([$tag->id]);
        }

        Log::info('support.account_ticket_created', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'user_id' => $user->id,
            'issue_type' => $this->issueType,
        ]);
    }

    /**
     * Build a human-readable description for the account support ticket.
     */
    private function buildTicketDescription(User $user): string
    {
        $issueLabel = __(self::ISSUE_TYPES[$this->issueType] ?? $this->issueType);

        $lines = [
            "**Submitted by:** {$user->name} ({$user->email})",
            "**Issue type:** {$issueLabel}",
            '',
            '**Details:**',
            $this->description,
        ];

        return implode("\n", $lines);
    }
}
