<?php

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Charter\CharterMemberService;
use App\Services\Charter\CharterService;
use Tests\Traits\CreatesUsers;

// M070/S04-T01 — CharterMemberService: the free-tier concurrent
// active-charter cap (R084) and the member seam (R085). Charters are
// built through the real lifecycle (start → publish) — active status is
// what counts, so signing/countersigning is not needed here.

uses(CreatesUsers::class);

/**
 * A campaign owned by $owner with deterministic quick-start inputs.
 */
function charter_member_campaign(User $owner): Campaign
{
    return Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'description' => ['en' => 'A noir intrigue campaign in the city of Threshold.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);
}

/**
 * Publish an ACTIVE charter on a fresh campaign owned by $owner through
 * the real lifecycle (start → publish → refresh).
 */
function charter_member_active_charter(User $owner): SessionZeroSurvey
{
    $campaign = charter_member_campaign($owner);
    $charter = CharterService::start($owner, $campaign);

    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    CharterService::publish($charter, (string) $campaign->name, $content);

    return $charter->refresh();
}

// ── canUseConveniences() — the member seam ───────────────

test('canUseConveniences is false for a plain free owner', function () {
    $owner = User::factory()->create();

    expect(CharterMemberService::canUseConveniences($owner))->toBeFalse();
});

test('canUseConveniences is true for a subscribed active GM', function () {
    $member = $this->createSubscribedGm();

    expect(CharterMemberService::canUseConveniences($member))->toBeTrue();
});

// ── activeCharterCount() — slot accounting ───────────────

test('activeCharterCount starts at zero and counts only active charters on owned campaigns', function () {
    $owner = User::factory()->create();

    expect(CharterMemberService::activeCharterCount($owner))->toBe(0);

    charter_member_active_charter($owner);

    expect(CharterMemberService::activeCharterCount($owner))->toBe(1);

    charter_member_active_charter($owner);

    expect(CharterMemberService::activeCharterCount($owner))->toBe(2);
});

test('drafts do not consume a slot', function () {
    $owner = User::factory()->create();
    CharterService::start($owner, charter_member_campaign($owner));

    expect(CharterMemberService::activeCharterCount($owner))->toBe(0);
});

test('other owners charters do not consume this owners slots', function () {
    $other = User::factory()->create();
    charter_member_active_charter($other);
    charter_member_active_charter($other);

    $owner = User::factory()->create();

    expect(CharterMemberService::activeCharterCount($owner))->toBe(0);
});

// ── atCharterLimit() — the R084/R085 cap ─────────────────

test('a free owner is limited exactly at the cap, not below it', function () {
    $owner = User::factory()->create();

    charter_member_active_charter($owner);
    expect(CharterMemberService::atCharterLimit($owner))->toBeFalse();

    charter_member_active_charter($owner);
    expect(CharterMemberService::atCharterLimit($owner))->toBeTrue();
});

test('a member is never at the limit, even past the cap', function () {
    $member = $this->createSubscribedGm();

    charter_member_active_charter($member);
    charter_member_active_charter($member);
    charter_member_active_charter($member);

    expect(CharterMemberService::atCharterLimit($member))->toBeFalse();
});

test('archiving an active charter frees the slot', function () {
    $owner = User::factory()->create();
    $first = charter_member_active_charter($owner);
    charter_member_active_charter($owner);

    expect(CharterMemberService::atCharterLimit($owner))->toBeTrue();

    CharterService::archive($owner, $first);

    expect(CharterMemberService::activeCharterCount($owner))->toBe(1)
        ->and(CharterMemberService::atCharterLimit($owner))->toBeFalse();
});
