<?php

use App\Models\Game;
use App\Models\User;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityInvitation;
use App\Notifications\NewFollower;
use App\Notifications\ParticipantJoined;
use App\Services\NotificationQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

beforeEach(function () {
    $this->service = new NotificationQueryService;
    $this->user = User::factory()->create();
});

// ── getGroupedForUser ─────────────────────────────────────────

describe('getGroupedForUser', function () {
    it('groups single notification correctly including group_key structure', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        $groups = $this->service->getGroupedForUser($this->user);

        expect($groups)->toHaveCount(1);

        $group = $groups->first();
        expect($group->type)->toBe('NewFollower');
        expect($group->count)->toBe(1);
        expect($group->actorNames)->toBe(['Alice']);
        expect($group->displayString)->toBe('Alice followed you');
        expect($group->isRead)->toBeFalse();
        // group_key includes type and date
        expect($group->groupKey)->toStartWith('NewFollower_');
        expect($group->groupKey)->toContain(now()->toDateString());
    });

    it('groups multiple same-type notifications on the same day', function () {
        $follower1 = User::factory()->create(['name' => 'Alice']);
        $follower2 = User::factory()->create(['name' => 'Bob']);
        $follower3 = User::factory()->create(['name' => 'Carol']);

        $this->user->notify(new NewFollower($follower1));
        $this->user->notify(new NewFollower($follower2));
        $this->user->notify(new NewFollower($follower3));

        $groups = $this->service->getGroupedForUser($this->user);

        expect($groups)->toHaveCount(1);

        $group = $groups->first();
        expect($group->type)->toBe('NewFollower');
        expect($group->count)->toBe(3);
        expect($group->actorNames)->toHaveCount(3);
        expect($group->displayString)->toBe('Alice, Bob, and 1 other followed you');
        expect($group->isRead)->toBeFalse();
    });

    it('creates separate groups for different notification types', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $inviter = User::factory()->create();
        $game = Game::factory()->create();

        $this->user->notify(new NewFollower($follower));
        $this->user->notify(new EntityInvitation($game, $inviter));

        $groups = $this->service->getGroupedForUser($this->user);

        expect($groups)->toHaveCount(2);

        $types = $groups->pluck('type')->toArray();
        expect($types)->toContain('NewFollower');
        expect($types)->toContain('EntityInvitation');
    });

    it('marks group as read when all notifications are read', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        $this->user->notifications()->first()->markAsRead();

        $groups = $this->service->getGroupedForUser($this->user);
        expect($groups->first()->isRead)->toBeTrue();
    });

    it('marks group as unread when any notification is unread', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));
        $this->user->notify(new NewFollower($follower));

        // Mark only first as read
        $this->user->notifications()->first()->markAsRead();

        $groups = $this->service->getGroupedForUser($this->user);
        expect($groups->first()->isRead)->toBeFalse();
    });

    it('produces two-actor display string correctly', function () {
        $follower1 = User::factory()->create(['name' => 'Alice']);
        $follower2 = User::factory()->create(['name' => 'Bob']);

        $this->user->notify(new NewFollower($follower1));
        $this->user->notify(new NewFollower($follower2));

        $groups = $this->service->getGroupedForUser($this->user);
        expect($groups->first()->displayString)->toBe('Alice and Bob followed you');
    });

    it('deduplicates actor names', function () {
        $follower = User::factory()->create(['name' => 'Alice']);

        // Same follower sends multiple follow notifications
        $this->user->notify(new NewFollower($follower));
        $this->user->notify(new NewFollower($follower));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->count)->toBe(2);
        expect($group->actorNames)->toBe(['Alice']);
        expect($group->displayString)->toBe('Alice followed you');
    });

    it('resolves category from notification type', function () {
        $follower = User::factory()->create();
        $this->user->notify(new NewFollower($follower));

        $groups = $this->service->getGroupedForUser($this->user);
        expect($groups->first()->category)->toBe('new_follower');
    });
});

// ── getPaginatedForUser ───────────────────────────────────────

describe('getPaginatedForUser', function () {
    it('returns paginator with grouped items', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        $paginator = $this->service->getPaginatedForUser($this->user);

        expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
        expect($paginator->items())->toHaveCount(1);
        expect($paginator->total())->toBe(1);
    });

    it('paginates correctly with multiple pages', function () {
        for ($i = 0; $i < 25; $i++) {
            $follower = User::factory()->create(['name' => "User{$i}"]);
            $this->user->notify(new NewFollower($follower));
        }

        $paginator = $this->service->getPaginatedForUser($this->user, 10);

        expect($paginator->total())->toBe(25);
        expect($paginator->perPage())->toBe(10);
    });

    it('preserves group structure in paginated results', function () {
        $follower1 = User::factory()->create(['name' => 'Alice']);
        $follower2 = User::factory()->create(['name' => 'Bob']);

        $this->user->notify(new NewFollower($follower1));
        $this->user->notify(new NewFollower($follower2));

        $paginator = $this->service->getPaginatedForUser($this->user);

        $items = $paginator->items();
        expect($items)->toHaveCount(1);

        $group = $items[0];
        expect($group->count)->toBe(2);
        expect($group->displayString)->toBe('Alice and Bob followed you');
    });
});

// ── display string patterns ───────────────────────────────────

describe('display strings', function () {
    it('handles notification types without actors (status changes)', function () {
        $game = Game::factory()->create(['name' => ['en' => 'Epic Quest']]);
        $this->user->notify(new EntityCancelled($game));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->actorNames)->toBeEmpty();
        expect($group->displayString)->toBe('Game cancelled: Epic Quest');
    });

    it('handles invitation display strings with entity context', function () {
        $inviter = User::factory()->create(['name' => 'Dana']);
        $game = Game::factory()->create(['name' => ['en' => 'Test Game']]);

        $this->user->notify(new EntityInvitation($game, $inviter));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->displayString)->toBe('Dana invited you to a game');
    });

    it('handles participant joined with entity context', function () {
        $participant = User::factory()->create(['name' => 'Eve']);
        $game = Game::factory()->create(['name' => ['en' => 'My Game']]);

        $this->user->notify(new ParticipantJoined($participant, $game, 'game'));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->displayString)->toBe('Eve joined');
    });
});
