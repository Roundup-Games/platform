<?php

use App\Enums\NotificationCategory;
use App\Models\GameSystem;
use App\Models\GameSystemRequest;
use App\Models\User;
use App\Notifications\GameSystemRequestApproved;
use App\Notifications\GameSystemRequestDuplicate;
use App\Notifications\GameSystemRequestRejected;
use App\Services\NotificationService;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ── Real database channel persistence ─────────────────

describe('Database channel persistence', function () {
    it('persists GameSystemRequestApproved in the notifications table', function () {
        $user = User::factory()->create();
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $gameSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $user->notifyNow(new GameSystemRequestApproved($request, $gameSystem));

        $notification = $user->notifications()->where('type', GameSystemRequestApproved::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('game_system_request_approved')
            ->and($notification->data['request_id'])->toBe($request->id)
            ->and($notification->data['game_system_id'])->toBe($gameSystem->id)
            ->and($notification->data['game_system_name'])->toBe('Catan')
            ->and($notification->data['game_system_slug'])->toBe('catan')
            ->and($notification->data['action_url'])->toContain('/game-systems/catan');
    });

    it('persists GameSystemRequestRejected with rejection reason', function () {
        $user = User::factory()->create();
        $request = GameSystemRequest::factory()->create([
            'name' => 'Monopoly',
            'rejection_reason' => 'Already exists in the catalog.',
        ]);

        $user->notifyNow(new GameSystemRequestRejected($request));

        $notification = $user->notifications()->where('type', GameSystemRequestRejected::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('game_system_request_rejected')
            ->and($notification->data['request_id'])->toBe($request->id)
            ->and($notification->data['game_system_name'])->toBe('Monopoly')
            ->and($notification->data['rejection_reason'])->toBe('Already exists in the catalog.')
            ->and($notification->data['message'])->toContain('Monopoly')
            ->and($notification->data['message'])->toContain('Already exists');
    });

    it('persists GameSystemRequestRejected without rejection reason', function () {
        $user = User::factory()->create();
        $request = GameSystemRequest::factory()->create([
            'name' => 'Risk',
            'rejection_reason' => null,
        ]);

        $user->notifyNow(new GameSystemRequestRejected($request));

        $notification = $user->notifications()->where('type', GameSystemRequestRejected::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['rejection_reason'])->toBeNull()
            ->and($notification->data['message'])->toContain('Risk')
            ->and($notification->data['message'])->not->toContain('Reason');
    });

    it('persists GameSystemRequestDuplicate with existing system data', function () {
        $user = User::factory()->create();
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $existingSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $user->notifyNow(new GameSystemRequestDuplicate($request, $existingSystem));

        $notification = $user->notifications()->where('type', GameSystemRequestDuplicate::class)->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('game_system_request_duplicate')
            ->and($notification->data['request_id'])->toBe($request->id)
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
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $gameSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $mail = (new GameSystemRequestApproved($request, $gameSystem))->toMail($user);

        expect($mail->subject)->toContain('Catan')
            ->and($mail->actionUrl)->toContain('/games/create')
            ->and($mail->actionUrl)->toContain('game_system_id=' . $gameSystem->id)
            ->and($mail->actionText)->toBe('Create a Game');
    });

    it('renders rejected mail with subject and no action button', function () {
        $user = User::factory()->create(['name' => 'Bob']);
        $request = GameSystemRequest::factory()->create([
            'name' => 'Monopoly',
            'rejection_reason' => 'Not enough info.',
        ]);

        $mail = (new GameSystemRequestRejected($request))->toMail($user);

        expect($mail->subject)->toBe('Game System Request Update')
            ->and($mail->actionUrl)->toBeNull();
    });

    it('renders rejected mail without reason line when null', function () {
        $user = User::factory()->create(['name' => 'Carol']);
        $request = GameSystemRequest::factory()->create([
            'name' => 'Risk',
            'rejection_reason' => null,
        ]);

        $mail = (new GameSystemRequestRejected($request))->toMail($user);

        expect($mail->subject)->toBe('Game System Request Update')
            ->and($mail->actionUrl)->toBeNull();
    });

    it('renders duplicate mail with existing system link', function () {
        $user = User::factory()->create(['name' => 'Dave']);
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $existingSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);

        $mail = (new GameSystemRequestDuplicate($request, $existingSystem))->toMail($user);

        expect($mail->subject)->toBe('Game System Already Exists')
            ->and($mail->actionUrl)->toContain('/game-systems/catan')
            ->and($mail->actionText)->toBe('View Game System');
    });
});

// ── Full end-to-end via NotificationService ────────────

describe('End-to-end dispatch via NotificationService', function () {
    it('stores approved notification in database and asserts full data round-trip', function () {
        $user = User::factory()->create();
        $request = GameSystemRequest::factory()->create(['user_id' => $user->id, 'name' => 'Twilight Imperium']);
        $gameSystem = GameSystem::factory()->create(['name' => 'Twilight Imperium', 'slug' => 'twilight-imperium']);

        app(NotificationService::class)->send(
            $user,
            new GameSystemRequestApproved($request, $gameSystem),
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
        $request = GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Chess 2',
            'rejection_reason' => 'Chess already exists.',
        ]);

        app(NotificationService::class)->send(
            $user,
            new GameSystemRequestRejected($request),
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
        $request = GameSystemRequest::factory()->create(['user_id' => $user->id, 'name' => 'Checkers']);
        $existingSystem = GameSystem::factory()->create(['name' => 'Checkers', 'slug' => 'checkers']);

        app(NotificationService::class)->send(
            $user,
            new GameSystemRequestDuplicate($request, $existingSystem),
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
        $request = GameSystemRequest::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        expect((new GameSystemRequestApproved($request, $gameSystem))->getActor())->toBeNull();
    });

    it('returns null for rejected notification', function () {
        $request = GameSystemRequest::factory()->create();

        expect((new GameSystemRequestRejected($request))->getActor())->toBeNull();
    });

    it('returns null for duplicate notification', function () {
        $request = GameSystemRequest::factory()->create();
        $existingSystem = GameSystem::factory()->create();

        expect((new GameSystemRequestDuplicate($request, $existingSystem))->getActor())->toBeNull();
    });
});

// ── via() channel contract ────────────────────────────

describe('via() returns database and mail channels', function () {
    it('returns correct channels for approved notification', function () {
        $request = GameSystemRequest::factory()->create();
        $gameSystem = GameSystem::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new GameSystemRequestApproved($request, $gameSystem))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });

    it('returns correct channels for rejected notification', function () {
        $request = GameSystemRequest::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new GameSystemRequestRejected($request))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });

    it('returns correct channels for duplicate notification', function () {
        $request = GameSystemRequest::factory()->create();
        $existingSystem = GameSystem::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new GameSystemRequestDuplicate($request, $existingSystem))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });
});
