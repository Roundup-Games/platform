<?php

use App\Enums\NotificationCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\GameSystemRequestApproved;
use App\Notifications\GameSystemRequestDuplicate;
use App\Notifications\GameSystemRequestRejected;
use App\Services\NotificationService;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->seed(EscalatedSetupSeeder::class);
    $this->department = Department::where('name', 'Game Systems')->firstOrFail();

    $this->user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
});

// ── Helper: create a game system request ticket ─────

function createGameSystemRequestTicket(User $user, Department $department, array $overrides = []): Ticket
{
    $defaults = [
        'requester_type' => User::class,
        'requester_id' => $user->id,
        'subject' => 'Game System Request: Catan',
        'description' => 'Please add Catan.',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department->id,
        'ticket_type' => 'game_system_request',
        'channel' => 'web',
        'metadata' => [
            'game_system_request' => true,
            'bgg_url' => null,
            'publisher' => null,
            'designer' => null,
            'game_system_type' => 'boardgame',
            'game_system_id' => null,
        ],
    ];

    return Ticket::create(array_merge($defaults, $overrides));
}

// ── Real database channel persistence ─────────────────

describe('Database channel persistence', function () {
    it('persists GameSystemRequestApproved in the notifications table', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department, [
            'subject' => 'Game System Request: Catan',
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $this->user->notifyNow(new GameSystemRequestApproved($ticket, $gameSystem));

        $notification = $this->user->notifications()->where('type', GameSystemRequestApproved::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('game_system_request_approved')
            ->and($notification->data['ticket_id'])->toBe($ticket->id)
            ->and($notification->data['game_system_id'])->toBe($gameSystem->id)
            ->and($notification->data['game_system_name'])->toBe('Catan')
            ->and($notification->data['game_system_slug'])->toBe('catan')
            ->and($notification->data['action_url'])->toContain('/game-systems/catan');
    });

    it('persists GameSystemRequestRejected with rejection reason', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department, [
            'subject' => 'Game System Request: Monopoly',
            'metadata' => [
                'game_system_request' => true,
                'rejection_reason' => 'Already exists in the catalog.',
            ],
        ]);

        $this->user->notifyNow(new GameSystemRequestRejected($ticket));

        $notification = $this->user->notifications()->where('type', GameSystemRequestRejected::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('game_system_request_rejected')
            ->and($notification->data['ticket_id'])->toBe($ticket->id)
            ->and($notification->data['game_system_name'])->toBe('Monopoly')
            ->and($notification->data['rejection_reason'])->toBe('Already exists in the catalog.')
            ->and($notification->data['message'])->toContain('Monopoly')
            ->and($notification->data['message'])->toContain('Already exists');
    });

    it('persists GameSystemRequestRejected without rejection reason', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department, [
            'subject' => 'Game System Request: Risk',
        ]);

        $this->user->notifyNow(new GameSystemRequestRejected($ticket));

        $notification = $this->user->notifications()->where('type', GameSystemRequestRejected::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['rejection_reason'])->toBeNull()
            ->and($notification->data['message'])->toContain('Risk')
            ->and($notification->data['message'])->not->toContain('Reason');
    });

    it('persists GameSystemRequestDuplicate with existing system data', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department, [
            'subject' => 'Game System Request: Catan',
        ]);
        $existingSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $this->user->notifyNow(new GameSystemRequestDuplicate($ticket, $existingSystem));

        $notification = $this->user->notifications()->where('type', GameSystemRequestDuplicate::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('game_system_request_duplicate')
            ->and($notification->data['ticket_id'])->toBe($ticket->id)
            ->and($notification->data['existing_game_system_id'])->toBe($existingSystem->id)
            ->and($notification->data['existing_game_system_name'])->toBe('Catan')
            ->and($notification->data['existing_game_system_slug'])->toBe('catan')
            ->and($notification->data['action_url'])->toContain('/game-systems/catan');
    });
});

// ── Mail channel rendering ────────────────────────────

describe('Mail channel rendering', function () {
    it('renders approved mail with correct subject and action button', function () {
        $user = User::factory()->create(['name' => 'Alice']);
        $ticket = createGameSystemRequestTicket($user, $this->department, [
            'subject' => 'Game System Request: Catan',
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $mail = (new GameSystemRequestApproved($ticket, $gameSystem))->toMail($user);

        expect($mail->subject)->toContain('Catan')
            ->and($mail->actionUrl)->toContain('/games/create')
            ->and($mail->actionUrl)->toContain('game_system_id=' . $gameSystem->id)
            ->and($mail->actionText)->toBe('Create a Game');
    });

    it('renders rejected mail with subject and no action button', function () {
        $user = User::factory()->create(['name' => 'Bob']);
        $ticket = createGameSystemRequestTicket($user, $this->department, [
            'subject' => 'Game System Request: Monopoly',
            'metadata' => [
                'game_system_request' => true,
                'rejection_reason' => 'Not enough info.',
            ],
        ]);

        $mail = (new GameSystemRequestRejected($ticket))->toMail($user);

        expect($mail->subject)->toBe('Game System Request Update')
            ->and($mail->actionUrl)->toBeNull();
    });

    it('renders rejected mail without reason line when null', function () {
        $user = User::factory()->create(['name' => 'Carol']);
        $ticket = createGameSystemRequestTicket($user, $this->department, [
            'subject' => 'Game System Request: Risk',
        ]);

        $mail = (new GameSystemRequestRejected($ticket))->toMail($user);

        expect($mail->subject)->toBe('Game System Request Update')
            ->and($mail->actionUrl)->toBeNull();
    });

    it('renders duplicate mail with existing system link', function () {
        $user = User::factory()->create(['name' => 'Dave']);
        $ticket = createGameSystemRequestTicket($user, $this->department, [
            'subject' => 'Game System Request: Catan',
        ]);
        $existingSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $mail = (new GameSystemRequestDuplicate($ticket, $existingSystem))->toMail($user);

        expect($mail->subject)->toBe('Game System Already Exists')
            ->and($mail->actionUrl)->toContain('/game-systems/catan')
            ->and($mail->actionText)->toBe('View Game System');
    });
});

// ── Full end-to-end via NotificationService ────────────

describe('End-to-end dispatch via NotificationService', function () {
    it('stores approved notification in database and asserts full data round-trip', function () {
        $user = User::factory()->create();
        $ticket = createGameSystemRequestTicket($user, $this->department, [
            'subject' => 'Game System Request: Twilight Imperium',
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'Twilight Imperium', 'slug' => 'twilight-imperium']);

        app(NotificationService::class)->send(
            $user,
            new GameSystemRequestApproved($ticket, $gameSystem),
            NotificationCategory::GameSystemRequest,
        );

        $notification = $user->notifications()->where('type', GameSystemRequestApproved::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['game_system_name'])->toBe('Twilight Imperium')
            ->and($notification->data['game_system_slug'])->toBe('twilight-imperium')
            ->and($notification->data['action_url'])->toContain('/game-systems/twilight-imperium');
    });

    it('stores rejected notification in database with reason', function () {
        $user = User::factory()->create();
        $ticket = createGameSystemRequestTicket($user, $this->department, [
            'subject' => 'Game System Request: Chess 2',
            'metadata' => [
                'game_system_request' => true,
                'rejection_reason' => 'Chess already exists.',
            ],
        ]);

        app(NotificationService::class)->send(
            $user,
            new GameSystemRequestRejected($ticket),
            NotificationCategory::GameSystemRequest,
        );

        $notification = $user->notifications()->where('type', GameSystemRequestRejected::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('game_system_request_rejected')
            ->and($notification->data['rejection_reason'])->toBe('Chess already exists.')
            ->and($notification->data['message'])->toContain('Chess 2');
    });

    it('stores duplicate notification in database with existing system data', function () {
        $user = User::factory()->create();
        $ticket = createGameSystemRequestTicket($user, $this->department, [
            'subject' => 'Game System Request: Checkers',
        ]);
        $existingSystem = GameSystem::factory()->create(['name' => 'Checkers', 'slug' => 'checkers']);

        app(NotificationService::class)->send(
            $user,
            new GameSystemRequestDuplicate($ticket, $existingSystem),
            NotificationCategory::GameSystemRequest,
        );

        $notification = $user->notifications()->where('type', GameSystemRequestDuplicate::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['existing_game_system_name'])->toBe('Checkers')
            ->and($notification->data['existing_game_system_slug'])->toBe('checkers')
            ->and($notification->data['action_url'])->toContain('/game-systems/checkers');
    });
});

// ── getActor() returns null (system notification) ──────

describe('getActor returns null for all game system request notifications', function () {
    it('returns null for approved notification', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department);
        $gameSystem = GameSystem::factory()->create();

        expect((new GameSystemRequestApproved($ticket, $gameSystem))->getActor())->toBeNull();
    });

    it('returns null for rejected notification', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department);

        expect((new GameSystemRequestRejected($ticket))->getActor())->toBeNull();
    });

    it('returns null for duplicate notification', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department);
        $existingSystem = GameSystem::factory()->create();

        expect((new GameSystemRequestDuplicate($ticket, $existingSystem))->getActor())->toBeNull();
    });
});

// ── via() channel contract ────────────────────────────

describe('via() returns database and mail channels', function () {
    it('returns correct channels for approved notification', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department);
        $gameSystem = GameSystem::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new GameSystemRequestApproved($ticket, $gameSystem))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });

    it('returns correct channels for rejected notification', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department);
        $notifiable = User::factory()->create();

        $channels = (new GameSystemRequestRejected($ticket))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });

    it('returns correct channels for duplicate notification', function () {
        $ticket = createGameSystemRequestTicket($this->user, $this->department);
        $existingSystem = GameSystem::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new GameSystemRequestDuplicate($ticket, $existingSystem))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });
});
