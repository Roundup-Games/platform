<?php

use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Mail\GuestVerificationCode;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Reply;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Notifications\NewTicketNotification;
use Escalated\Laravel\Notifications\TicketAssignedNotification;
use Escalated\Laravel\Notifications\TicketReplyNotification;
use Escalated\Laravel\Notifications\TicketResolvedNotification;
use Escalated\Laravel\Notifications\TicketStatusChangedNotification;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| Escalated ticket mail branding (M081/S03/T02)
|--------------------------------------------------------------------------
|
| Pins the roundup brand pass on the escalated support-desk mails: the six
| app overrides under resources/views/vendor/escalated/ (five markdown
| lifecycle mails + the text-only guest verification code) replace the
| vendor views through the escalated:: namespace resolution.
|
| The five lifecycle notifications render markdown MailMessages, so they
| inherit the S01 roundup theme (D279) automatically — what these tests
| pin is that the brand token actually reaches the rendered HTML and that
| the vendor-bespoke styling (the settings hex accent, which produced an
| unstyled button-<hex> class) is gone from the override output.
|
| SlaWarningNotification is database-only (no toMail) and the escalated /
| sla-breach mails stay vendor views by design (agent-facing) — neither
| belongs here.
*/

/**
 * Create a ticket for rendering fixtures (same required-field shape as
 * UserTicketIntegrationTest's createTestTicket — the vendor webhook
 * listener assumes priority is never null).
 *
 * Named differently from the other suite helpers to avoid a global
 * function redeclare across Pest files.
 */
function brandingMailTestTicket(User $user, Department $department, array $overrides = []): Ticket
{
    return Ticket::create(array_merge([
        'subject' => 'Branding fixture ticket',
        'description' => 'A fixture description used by the mail branding render tests.',
        'department_id' => $department->id,
        'requester_type' => (new User)->getMorphClass(),
        'requester_id' => $user->id,
        'priority' => TicketPriority::Medium,
    ], $overrides));
}

/**
 * Build the lifecycle notification under test for a dataset arm.
 */
function brandingMailTestNotification(string $case, Ticket $ticket, ?Reply $reply): Notification
{
    return match ($case) {
        'new-ticket' => new NewTicketNotification($ticket),
        'assigned' => new TicketAssignedNotification($ticket),
        'status-changed' => new TicketStatusChangedNotification($ticket, TicketStatus::Open, TicketStatus::InProgress),
        'resolved' => new TicketResolvedNotification($ticket),
        'reply' => new TicketReplyNotification($reply ?? throw new InvalidArgumentException('reply case needs a reply fixture')),
        default => throw new InvalidArgumentException("Unknown escalated mail branding case [{$case}]."),
    };
}

/**
 * Distinctive EN copy from each override view (hardcoded in the views,
 * except 'assigned' — that surface stays hardcoded EN per the K002 exemption,
 * so its heading stays a literal pin).
 */
function brandingMailExpectedHeading(string $case): string
{
    return match ($case) {
        'new-ticket' => __('escalation_mails.heading_new_ticket'),
        'assigned' => 'A ticket was assigned to you',
        'status-changed' => __('escalation_mails.heading_ticket_status_changed'),
        'resolved' => __('escalation_mails.heading_ticket_resolved'),
        'reply' => __('escalation_mails.heading_ticket_reply', ['reference' => '']),
    };
}

beforeEach(function () {
    app()->setLocale('en');

    $this->user = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->department = Department::firstOrCreate(
        ['name' => 'Contact'],
        ['description' => 'General inquiries', 'is_active' => true],
    );
});

it('renders each user-facing ticket lifecycle mail with the roundup brand token and reviewed EN copy', function (string $case) {
    $ticket = brandingMailTestTicket($this->user, $this->department, ['subject' => $case.' fixture']);
    $reply = $ticket->addReply($this->agent, 'We are looking into this.');

    $message = brandingMailTestNotification($case, $ticket, $reply)->toMail($this->user);

    // Subjects come from the escalated vendor lang lines — pin presence,
    // never literals (K004).
    expect(trim((string) $message->subject))->not->toBe('');

    $html = $message->render()->toHtml();

    expect($html)
        // S01 amber brand token, inlined by the markdown renderer
        // (header link + primary button rules).
        ->toContain('#835500')
        // Buttons ride the theme primary class — the vendor hex accent
        // (button-<hex> matches no theme rule and rendered unstyled) is gone.
        ->toContain('button-primary')
        ->not->toContain('#2d3748')
        ->not->toContain('button-#')
        // S01 layout chrome reaches the delivered HTML.
        ->toContain('<table class="wrapper"')
        // Brand name: the layout header and the override sign-off.
        ->toContain(e(config('app.name')))
        // Reviewed override copy: requester views read the keyed copy
        // (lang/*/escalation_mails.php); the agent-facing assigned view
        // stays hardcoded EN (K002 exemption).
        ->toContain(brandingMailExpectedHeading($case))
        ->toContain($case === 'assigned' ? 'Open Ticket' : __('escalation_mails.action_view_ticket'))
        ->toContain(($case === 'assigned' ? 'Thanks,' : __('escalation_mails.content_signoff').','))
        ->toContain(config('app.name').' Support');
})->with([
    'new-ticket',
    'assigned',
    'status-changed',
    'resolved',
    'reply',
]);

it('renders the guest verification code mail with the reviewed EN copy and the brand name', function () {
    // GuestVerificationCode is text-only (->text(), no HTML part) — the
    // brand surface is the brand name in the copy; no CSS token can
    // exist on this surface.
    $body = (string) (new GuestVerificationCode('483920'))->render();

    expect($body)
        ->toContain('483920')
        ->toContain('verification code')
        ->toContain(config('app.name'))
        ->toContain('expires in 10 minutes');
});
