<?php

use App\Models\User;
use App\Notifications\GameInvitation;
use App\Notifications\NewFollower;
use App\Notifications\ParticipantJoined;
use App\Services\NotificationQueryService;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->service = new NotificationQueryService();
    $this->user = User::factory()->create();
});

// ── getGroupedForUser ─────────────────────────────────────────

describe('getGroupedForUser', function () {
    it('returns empty collection for user with no notifications', function () {
        $result = $this->service->getGroupedForUser($this->user);

        expect($result)->toBeInstanceOf(Collection::class);
        expect($result)->toBeEmpty();
    });

    it('groups single notification correctly', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        $groups = $this->service->getGroupedForUser($this->user);

        expect($groups)->toHaveCount(1);

        $group = $groups->first();
        expect($group->type)->toBe('NewFollower');
        expect($group->count)->toBe(1);
        expect($group->actor_names)->toBe(['Alice']);
        expect($group->display_string)->toBe('Alice followed you');
        expect($group->is_read)->toBeFalse();
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
        expect($group->actor_names)->toHaveCount(3);
        expect($group->display_string)->toBe('Alice, Bob, and 1 other followed you');
        expect($group->is_read)->toBeFalse();
    });

    it('creates separate groups for different notification types', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $inviter = User::factory()->create();
        $game = \App\Models\Game::factory()->create();

        $this->user->notify(new NewFollower($follower));
        $this->user->notify(new GameInvitation($game, $inviter));

        $groups = $this->service->getGroupedForUser($this->user);

        expect($groups)->toHaveCount(2);

        $types = $groups->pluck('type')->toArray();
        expect($types)->toContain('NewFollower');
        expect($types)->toContain('GameInvitation');
    });

    it('respects the limit parameter', function () {
        for ($i = 0; $i < 5; $i++) {
            $follower = User::factory()->create(['name' => "User{$i}"]);
            $this->user->notify(new NewFollower($follower));
        }

        $groups = $this->service->getGroupedForUser($this->user, 2);

        // All 5 notifications collapse into 1 group (same type, same day)
        expect($groups)->toHaveCount(1);
    });

    it('marks group as read when all notifications are read', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        $this->user->notifications()->first()->markAsRead();

        $groups = $this->service->getGroupedForUser($this->user);
        expect($groups->first()->is_read)->toBeTrue();
    });

    it('marks group as unread when any notification is unread', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));
        $this->user->notify(new NewFollower($follower));

        // Mark only first as read
        $this->user->notifications()->first()->markAsRead();

        $groups = $this->service->getGroupedForUser($this->user);
        expect($groups->first()->is_read)->toBeFalse();
    });

    it('produces two-actor display string correctly', function () {
        $follower1 = User::factory()->create(['name' => 'Alice']);
        $follower2 = User::factory()->create(['name' => 'Bob']);

        $this->user->notify(new NewFollower($follower1));
        $this->user->notify(new NewFollower($follower2));

        $groups = $this->service->getGroupedForUser($this->user);
        expect($groups->first()->display_string)->toBe('Alice and Bob followed you');
    });

    it('deduplicates actor names', function () {
        $follower = User::factory()->create(['name' => 'Alice']);

        // Same follower sends multiple follow notifications
        $this->user->notify(new NewFollower($follower));
        $this->user->notify(new NewFollower($follower));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->count)->toBe(2);
        expect($group->actor_names)->toBe(['Alice']);
        expect($group->display_string)->toBe('Alice followed you');
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

        expect($paginator)->toBeInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class);
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
        expect($group->display_string)->toBe('Alice and Bob followed you');
    });
});

// ── display string patterns ───────────────────────────────────

describe('display strings', function () {
    it('handles notification types without actors (status changes)', function () {
        $game = \App\Models\Game::factory()->create(['name' => 'Epic Quest']);
        $this->user->notify(new \App\Notifications\GameCancelled($game));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->actor_names)->toBeEmpty();
        expect($group->display_string)->toBe('Game cancelled: Epic Quest');
    });

    it('handles invitation display strings with entity context', function () {
        $inviter = User::factory()->create(['name' => 'Dana']);
        $game = \App\Models\Game::factory()->create(['name' => 'Test Game']);

        $this->user->notify(new GameInvitation($game, $inviter));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->display_string)->toBe('Dana invited you to a game');
    });

    it('handles participant joined with entity context', function () {
        $participant = User::factory()->create(['name' => 'Eve']);
        $game = \App\Models\Game::factory()->create(['name' => 'My Game']);

        $this->user->notify(new ParticipantJoined($participant, $game, 'game'));

        $groups = $this->service->getGroupedForUser($this->user);
        $group = $groups->first();

        expect($group->display_string)->toBe('Eve joined');
    });
});

// ── group_key structure ───────────────────────────────────────

describe('group_key', function () {
    it('includes type and date in group_key', function () {
        $follower = User::factory()->create();
        $this->user->notify(new NewFollower($follower));

        $groups = $this->service->getGroupedForUser($this->user);
        $groupKey = $groups->first()->group_key;

        expect($groupKey)->toStartWith('NewFollower_');
        expect($groupKey)->toContain(now()->toDateString());
    });

    it('produces different group_keys for different types on same day', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $inviter = User::factory()->create();
        $game = \App\Models\Game::factory()->create();

        $this->user->notify(new NewFollower($follower));
        $this->user->notify(new GameInvitation($game, $inviter));

        $groups = $this->service->getGroupedForUser($this->user);
        $keys = $groups->pluck('group_key')->toArray();

        expect($keys)->toHaveCount(2);
        expect($keys[0])->not->toBe($keys[1]);
    });
});

// ── latest notification in group ──────────────────────────────

describe('latest notification', function () {
    it('sets latest to the most recent notification in the group', function () {
        $follower1 = User::factory()->create(['name' => 'Alice']);
        $follower2 = User::factory()->create(['name' => 'Bob']);

        $this->user->notify(new NewFollower($follower1));

        // Advance time slightly to ensure ordering
        $this->travel(1)->second();
        $this->user->notify(new NewFollower($follower2));

        $groups = $this->service->getGroupedForUser($this->user);
        $latest = $groups->first()->latest;

        $data = $latest->data;
        expect($data['follower_name'])->toBe('Bob');
    });
});
