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
