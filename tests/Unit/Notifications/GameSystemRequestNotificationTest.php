<?php

use App\Models\GameSystem;
use App\Models\GameSystemRequest;
use App\Models\User;
use App\Notifications\GameSystemRequestApproved;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('GameSystemRequestApproved', function () {
    it('stores correct data to database', function () {
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $gameSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);
        $notifiable = User::factory()->create();

        $data = (new GameSystemRequestApproved($request, $gameSystem))->toDatabase($notifiable);

        expect($data['type'])->toBe('game_system_request_approved')
            ->and($data['request_id'])->toBe($request->id)
            ->and($data['game_system_id'])->toBe($gameSystem->id)
            ->and($data['game_system_name'])->toBe('Catan')
            ->and($data['game_system_slug'])->toBe('catan')
            ->and($data['message'])->toContain('Catan')
            ->and($data['action_url'])->toContain('/game-systems/catan');
    });

    it('renders correct email content', function () {
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $gameSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);
        $notifiable = User::factory()->create(['name' => 'Requester']);

        $mail = (new GameSystemRequestApproved($request, $gameSystem))->toMail($notifiable);

        expect($mail->subject)->toContain('Catan')
            ->and($mail->actionUrl)->toContain('/games/create')
            ->and($mail->actionUrl)->toContain('game_system_id=' . $gameSystem->id)
            ->and($mail->actionText)->toBe('Create a Game');
    });

    it('returns null actor (system notification)', function () {
        $request = GameSystemRequest::factory()->create();
        $gameSystem = GameSystem::factory()->create();
        expect((new GameSystemRequestApproved($request, $gameSystem))->getActor())->toBeNull();
    });

    it('returns database and mail as fallback channels', function () {
        $request = GameSystemRequest::factory()->create();
        $gameSystem = GameSystem::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new GameSystemRequestApproved($request, $gameSystem))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });
});

describe('GameSystemRequestRejected', function () {
    it('stores correct data to database', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Monopoly',
            'rejection_reason' => 'Not enough information provided.',
        ]);
        $notifiable = User::factory()->create();

        $data = (new \App\Notifications\GameSystemRequestRejected($request))->toDatabase($notifiable);

        expect($data['type'])->toBe('game_system_request_rejected')
            ->and($data['request_id'])->toBe($request->id)
            ->and($data['game_system_name'])->toBe('Monopoly')
            ->and($data['rejection_reason'])->toBe('Not enough information provided.')
            ->and($data['message'])->toContain('Monopoly')
            ->and($data['message'])->toContain('Not enough information');
    });

    it('stores data without rejection reason', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Risk',
            'rejection_reason' => null,
        ]);
        $notifiable = User::factory()->create();

        $data = (new \App\Notifications\GameSystemRequestRejected($request))->toDatabase($notifiable);

        expect($data['type'])->toBe('game_system_request_rejected')
            ->and($data['rejection_reason'])->toBeNull()
            ->and($data['message'])->toContain('Risk')
            ->and($data['message'])->not->toContain('Reason');
    });

    it('renders correct email content with reason', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Monopoly',
            'rejection_reason' => 'Already exists.',
        ]);
        $notifiable = User::factory()->create(['name' => 'Requester']);

        $mail = (new \App\Notifications\GameSystemRequestRejected($request))->toMail($notifiable);

        expect($mail->subject)->toBe('Game System Request Update')
            ->and($mail->actionUrl)->toBeNull(); // informational, no action button
    });

    it('renders email without reason when null', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Risk',
            'rejection_reason' => null,
        ]);
        $notifiable = User::factory()->create(['name' => 'Requester']);

        $mail = (new \App\Notifications\GameSystemRequestRejected($request))->toMail($notifiable);

        expect($mail->subject)->toBe('Game System Request Update')
            ->and($mail->actionUrl)->toBeNull();
    });

    it('returns null actor (system notification)', function () {
        $request = GameSystemRequest::factory()->create();
        expect((new \App\Notifications\GameSystemRequestRejected($request))->getActor())->toBeNull();
    });

    it('returns database and mail as fallback channels', function () {
        $request = GameSystemRequest::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new \App\Notifications\GameSystemRequestRejected($request))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });
});

describe('GameSystemRequestDuplicate', function () {
    it('stores correct data to database', function () {
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $existingSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);
        $notifiable = User::factory()->create();

        $data = (new \App\Notifications\GameSystemRequestDuplicate($request, $existingSystem))->toDatabase($notifiable);

        expect($data['type'])->toBe('game_system_request_duplicate')
            ->and($data['request_id'])->toBe($request->id)
            ->and($data['existing_game_system_id'])->toBe($existingSystem->id)
            ->and($data['existing_game_system_name'])->toBe('Catan')
            ->and($data['existing_game_system_slug'])->toBe('catan')
            ->and($data['message'])->toContain('Catan')
            ->and($data['action_url'])->toContain('/game-systems/catan');
    });

    it('renders correct email content', function () {
        $request = GameSystemRequest::factory()->create(['name' => 'Catan']);
        $existingSystem = GameSystem::factory()->create(['name' => 'Catan', 'slug' => 'catan']);
        $notifiable = User::factory()->create(['name' => 'Requester']);

        $mail = (new \App\Notifications\GameSystemRequestDuplicate($request, $existingSystem))->toMail($notifiable);

        expect($mail->subject)->toBe('Game System Already Exists')
            ->and($mail->actionUrl)->toContain('/game-systems/catan')
            ->and($mail->actionText)->toBe('View Game System');
    });

    it('returns null actor (system notification)', function () {
        $request = GameSystemRequest::factory()->create();
        $existingSystem = GameSystem::factory()->create();
        expect((new \App\Notifications\GameSystemRequestDuplicate($request, $existingSystem))->getActor())->toBeNull();
    });

    it('returns database and mail as fallback channels', function () {
        $request = GameSystemRequest::factory()->create();
        $existingSystem = GameSystem::factory()->create();
        $notifiable = User::factory()->create();

        $channels = (new \App\Notifications\GameSystemRequestDuplicate($request, $existingSystem))->via($notifiable);

        expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
    });
});
