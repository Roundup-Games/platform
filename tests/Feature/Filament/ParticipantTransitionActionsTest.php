<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Filament\Concerns\RoutesParticipantTransitions;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->create();
    actingAs($this->admin);

    $this->owner = User::factory()->create();
    $this->game = Game::factory()->create([
        'owner_id' => $this->owner->id,
        'campaign_id' => null,
        'max_players' => 5,
        'min_players' => 2,
        'date_time' => now()->addDays(10),
    ]);
});

/**
 * Anonymous consumer of the trait exposing its private guard methods.
 * The guards are pure functions of a Participant's status/role — they do
 * not depend on Filament infrastructure — so they can be exercised in
 * isolation via reflection.
 */
function guards(): object
{
    return new class
    {
        use RoutesParticipantTransitions;
    };
}

function guard(object $instance, string $method, mixed ...$args): bool
{
    $reflection = new ReflectionMethod($instance, $method);
    $reflection->setAccessible(true);

    return (bool) $reflection->invoke($instance, ...$args);
}

function participant(Game $game, ParticipantStatus $status, ParticipantRole $role): GameParticipant
{
    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => $role->value,
        'status' => $status->value,
    ]);
}

describe('transition action visibility', function () {
    it('shows Approve / Reject only for pending applicants', function () {
        $guards = guards();
        $applicant = participant($this->game, ParticipantStatus::Pending, ParticipantRole::Applicant);

        expect(guard($guards, 'isApplicant', $applicant))->toBeTrue()
            ->and(guard($guards, 'isRemovable', $applicant))->toBeFalse()
            ->and(guard($guards, 'isPendingInvite', $applicant))->toBeFalse();
    });

    it('shows Cancel Invite only for pending invitees', function () {
        $guards = guards();
        $invitee = participant($this->game, ParticipantStatus::Pending, ParticipantRole::Invited);

        expect(guard($guards, 'isPendingInvite', $invitee))->toBeTrue()
            ->and(guard($guards, 'isApplicant', $invitee))->toBeFalse()
            ->and(guard($guards, 'isRemovable', $invitee))->toBeFalse();
    });

    it('shows Promote from Bench only for benched participants', function () {
        $guards = guards();
        $benched = participant($this->game, ParticipantStatus::Benched, ParticipantRole::Player);
        $approved = participant($this->game, ParticipantStatus::Approved, ParticipantRole::Player);

        expect(guard($guards, 'isStatus', $benched, ParticipantStatus::Benched))->toBeTrue()
            ->and(guard($guards, 'isStatus', $approved, ParticipantStatus::Benched))->toBeFalse()
            ->and(guard($guards, 'isRemovable', $benched))->toBeTrue();
    });

    it('shows Promote / Remove from Waitlist only for waitlisted participants', function () {
        $guards = guards();
        $waitlisted = participant($this->game, ParticipantStatus::Waitlisted, ParticipantRole::Player);

        expect(guard($guards, 'isRemovable', $waitlisted))->toBeTrue();
    });

    it('shows Remove for approved players but not for owners', function () {
        $guards = guards();
        $player = participant($this->game, ParticipantStatus::Approved, ParticipantRole::Player);
        $ownerRow = participant($this->game, ParticipantStatus::Approved, ParticipantRole::Owner);

        expect(guard($guards, 'isRemovable', $player))->toBeTrue()
            ->and(guard($guards, 'isRemovable', $ownerRow))->toBeFalse();
    });

    it('guards reject non-Participant records', function () {
        $guards = guards();

        expect(guard($guards, 'isApplicant', new stdClass))->toBeFalse()
            ->and(guard($guards, 'isRemovable', null))->toBeFalse()
            ->and(guard($guards, 'isStatus', 'not-a-record', ParticipantStatus::Benched))->toBeFalse();
    });
});
