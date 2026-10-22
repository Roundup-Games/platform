<?php

use App\Models\MembershipType;
use App\Models\User;
use App\Services\Badges\BadgeAward;
use App\Services\Badges\SupportingMemberEvaluator;
use Tests\Helpers\PaddleWebhooks;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

/**
 * BadgeAward::for() evidence pointer hygiene (D170): badge_user.evidence_id
 * is a native uuid column. The two pointer shapes beyond the ordinary
 * uuid-keyed model are pinned here — the non-uuid guard (warning + null
 * pointer instead of a Postgres uuid-parse crash on INSERT) and the
 * Cashier-anchored tenure branch of SupportingMemberEvaluator (bigint
 * keys can never be pointers, so a Paddle-earliest supporter carries a
 * null evidence pointer by design).
 */
test('a non-uuid evidence key carries a null pointer and warns instead of crashing', function () {
    $this->captureLogRecords();

    // Every mapped model today is uuid-keyed, so the non-uuid shape is
    // exercised through a mapped-class stand-in whose key is a legacy
    // bigint — exactly what badge_user.evidence_id cannot store.
    $evidence = new class extends User
    {
        public function getKey(): int
        {
            return 42;
        }

        public function getMorphClass(): string
        {
            return (new User)->getMorphClass();
        }
    };

    $award = BadgeAward::for('supporting_member', 'year_1', $evidence);

    expect($award->evidenceType)->toBe($evidence->getMorphClass())
        ->and($award->evidenceId)->toBeNull();

    $warnings = collect($this->logRecords())
        ->firstWhere('message', 'badges.award.evidence_id_not_uuid');

    expect($warnings)->not->toBeNull()
        ->and($warnings['context']['badge'])->toBe('supporting_member');
});

test('a Cashier-earliest supporter anchors tenure on the subscription but carries no evidence pointer', function () {
    $user = User::factory()->create();

    // Local GM-plan support started recently...
    $gmPlan = MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        ['price_cents' => 0, 'duration_months' => 0, 'status' => 'active', 'type' => 'local', 'paddle_price_id' => null, 'metadata' => ['gm_plan' => true]],
    );
    $user->localSubscriptions()->create([
        'membership_type_id' => $gmPlan->id,
        'status' => 'active',
        'starts_at' => now()->subMonths(3)->toDateTimeString(),
        'ends_at' => null,
        'source' => 'self_serve',
    ]);

    // ...but Paddle reported a subscription two years earlier: the
    // Cashier row wins the tenure clock (year_3 sits at 24 months)
    // while its bigint key disqualifies it as the evidence pointer.
    PaddleWebhooks::createSubscription($user, [
        'created_at' => now()->subYears(2)->toDateTimeString(),
    ]);

    $award = app(SupportingMemberEvaluator::class)->evaluate($user);

    expect($award)->not->toBeNull()
        ->and($award->tierKey)->toBe('year_3')
        ->and($award->evidenceType)->toBeNull()
        ->and($award->evidenceId)->toBeNull();
});

test('a uuid-keyed evidence model keeps its pointer untouched', function () {
    $user = User::factory()->create();

    $award = BadgeAward::for('debut', null, $user);

    expect($award->evidenceType)->toBe($user->getMorphClass())
        ->and($award->evidenceId)->toBe($user->id);
});
