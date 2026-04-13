<?php

use App\Livewire\Events\EventListing;
use App\Livewire\Events\ManageRegistrations;
use App\Livewire\Teams\BrowseTeams;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\LinkedAccount;
use App\Models\MembershipType;
use App\Models\Team;
use App\Models\User;
use App\Traits\EscapesLikeWildcards;

// ── M1: withTimestamps removed from User::teams() ──────────────────────

test('User::teams() relationship does not call withTimestamps', function () {
    $reflection = new ReflectionMethod(User::class, 'teams');
    $source = file($reflection->getFileName());
    $startLine = $reflection->getStartLine() - 1;
    $endLine = $reflection->getEndLine();
    $methodSource = implode('', array_slice($source, $startLine, $endLine - $startLine));

    expect($methodSource)->not->toContain('withTimestamps');
});

test('team_members pivot has no created_at or updated_at columns', function () {
    $pivot = new \App\Models\TeamMember;
    $casts = $pivot->getCasts();

    // Verify that the pivot model doesn't expect timestamp columns
    expect($pivot->timestamps)->toBeFalse();
});

// ── M3: OAuth unique constraint + race condition handling ──────────────

test('linked_accounts table has unique constraint on provider and provider_user_id', function () {
    $user = User::factory()->create();
    $user2 = User::factory()->create();

    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '12345',
        'token' => 'token1',
    ]);

    // Second insert with same provider+provider_user_id should fail
    $this->expectException(\Illuminate\Database\QueryException::class);
    LinkedAccount::create([
        'user_id' => $user2->id,
        'provider' => 'google',
        'provider_user_id' => '12345',
        'token' => 'token2',
    ]);
});

test('linked_accounts allows same user to link multiple providers', function () {
    $user = User::factory()->create();

    $account1 = LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '111',
        'token' => 't1',
    ]);

    $account2 = LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => '222',
        'token' => 't2',
    ]);

    expect($account1)->not->toBeNull()
        ->and($account2)->not->toBeNull();
});

// ── M4: LIKE wildcard escaping ────────────────────────────────────────

test('EscapesLikeWildcards trait escapes percent sign', function () {
    $component = new class {
        use EscapesLikeWildcards;
    };

    expect($component->escapeLikeWildcards('50%'))->toBe('50\%');
});

test('EscapesLikeWildcards trait escapes underscore', function () {
    $component = new class {
        use EscapesLikeWildcards;
    };

    expect($component->escapeLikeWildcards('hello_world'))->toBe('hello\_world');
});

test('EscapesLikeWildcards trait escapes backslash first', function () {
    $component = new class {
        use EscapesLikeWildcards;
    };

    expect($component->escapeLikeWildcards('path\\to\\100%'))->toBe('path\\\\to\\\\100\%');
});

test('LIKE search with percent sign does not match all rows', function () {
    // Create two events with distinct names
    Event::factory()->create(['name' => 'Event Alpha', 'slug' => 'event-alpha', 'is_public' => true, 'status' => 'published', 'organizer_id' => User::factory()->create()->id]);
    Event::factory()->create(['name' => 'Event Beta', 'slug' => 'event-beta', 'is_public' => true, 'status' => 'published', 'organizer_id' => User::factory()->create()->id]);

    // A literal '%' search should NOT match everything
    $component = new class {
        use EscapesLikeWildcards;
    };
    $escaped = $component->escapeLikeWildcards('%');
    $matches = Event::where('name', 'like', "%{$escaped}%")->count();

    // Only an event literally containing '%' would match — none do
    expect($matches)->toBe(0);
});

test('LIKE search with underscore does not match any single character', function () {
    Event::factory()->create(['name' => 'Event A', 'slug' => 'event-a', 'is_public' => true, 'status' => 'published', 'organizer_id' => User::factory()->create()->id]);
    Event::factory()->create(['name' => 'Event B', 'slug' => 'event-b', 'is_public' => true, 'status' => 'published', 'organizer_id' => User::factory()->create()->id]);

    $component = new class {
        use EscapesLikeWildcards;
    };
    $escaped = $component->escapeLikeWildcards('Event _');
    $matches = Event::where('name', 'like', "%{$escaped}%")->count();

    // Without escaping, "Event _" would match both "Event A" and "Event B"
    expect($matches)->toBe(0);
});

// ── M19: Dead policy methods removed ──────────────────────────────────

test('TeamPolicy has no restore or forceDelete methods', function () {
    $policy = new \App\Policies\TeamPolicy;
    expect(method_exists($policy, 'restore'))->toBeFalse()
        ->and(method_exists($policy, 'forceDelete'))->toBeFalse();
});

test('EventPolicy has no restore or forceDelete methods', function () {
    $policy = new \App\Policies\EventPolicy;
    expect(method_exists($policy, 'restore'))->toBeFalse()
        ->and(method_exists($policy, 'forceDelete'))->toBeFalse();
});

test('GamePolicy has no restore or forceDelete methods', function () {
    $policy = new \App\Policies\GamePolicy;
    expect(method_exists($policy, 'restore'))->toBeFalse()
        ->and(method_exists($policy, 'forceDelete'))->toBeFalse();
});

test('CampaignPolicy has no restore or forceDelete methods', function () {
    $policy = new \App\Policies\CampaignPolicy;
    expect(method_exists($policy, 'restore'))->toBeFalse()
        ->and(method_exists($policy, 'forceDelete'))->toBeFalse();
});

test('UserPolicy has no restore or forceDelete methods', function () {
    $policy = new \App\Policies\UserPolicy;
    expect(method_exists($policy, 'restore'))->toBeFalse()
        ->and(method_exists($policy, 'forceDelete'))->toBeFalse();
});

test('MembershipTypePolicy has no restore or forceDelete methods', function () {
    $policy = new \App\Policies\MembershipTypePolicy;
    expect(method_exists($policy, 'restore'))->toBeFalse()
        ->and(method_exists($policy, 'forceDelete'))->toBeFalse();
});

test('no model uses SoftDeletes trait', function () {
    $modelFiles = glob(app_path('Models/*.php'));
    $softDeleteModels = [];

    foreach ($modelFiles as $file) {
        $contents = file_get_contents($file);
        if (str_contains($contents, 'SoftDeletes')) {
            $softDeleteModels[] = basename($file, '.php');
        }
    }

    expect($softDeleteModels)->toBeEmpty('Models using SoftDeletes: ' . implode(', ', $softDeleteModels));
});
