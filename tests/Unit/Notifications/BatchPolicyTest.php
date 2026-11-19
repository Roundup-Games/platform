<?php

use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\NewApplication;
use App\Notifications\ParticipantJoined;
use App\Notifications\SessionAddedToCampaign;

// Unit/Notifications is a container-only directory (no DatabaseTransactions
// — see tests/Pest.php): every model here is factory()->make() unsaved with
// hand-set ids, so batchKey() is exercised without writing a single row.

describe('NotificationCategory::isBatchable', function () {
    it('batches exactly the v1 host-side bursty set (D296)', function () {
        $batchable = [];
        foreach (NotificationCategory::cases() as $category) {
            if ($category->isBatchable()) {
                $batchable[] = $category->value;
            }
        }
        sort($batchable);

        // Exact-set pin: accidental widening (or narrowing) of the batch
        // policy fails here before it reaches the dispatch path (T04) or
        // the S03 matrix page.
        expect($batchable)->toBe([
            'new_application',
            'participant_joined',
            'session_added_to_campaign',
        ]);
    });

    it('never batches deadline- or plan-critical categories', function (string $value) {
        expect(NotificationCategory::from($value)->isBatchable())->toBeFalse();
    })->with([
        // Invitations expire — a delayed delivery burns decision time
        'game_invitation',
        'campaign_invitation',
        'team_invitation',
        // Seat changes cost the recipient their spot if unseen promptly
        'waitlist_promoted',
        'seat_demoted',
        // Cancellations change plans
        'game_cancelled',
        'campaign_cancelled',
        // Moderation
        'moderation_notice',
        'review_reported',
        // Attendance / nudges / reminders / expiry are clock-critical
        'attendance_nudge',
        'attendance_resolved',
        'session_reminder',
        'confirmation_expired',
        // Application outcomes act on the applicant's own plans
        'application_approved',
        'application_rejected',
    ]);
});

describe('BaseNotification::batchKey', function () {
    it('defaults to null — un-overridden notifications never batch', function () {
        $notifiable = User::factory()->make();

        $plain = new class extends BaseNotification {};

        expect($plain->batchKey($notifiable))->toBeNull();
    });

    it('keys each batchable category to its entity burst as category:entityUuid', function () {
        $notifiable = User::factory()->make();
        $participant = User::factory()->make();

        // Explicit owner_id strings (ProximityQuery precedent): a nested
        // User::factory() in the factories' definitions is PERSISTED even
        // under make() (expandAttributes -> create()->getKey()), which
        // would violate this directory's no-DB-write contract.
        $game = Game::factory()->make(['owner_id' => '0193d0aa-0000-7000-8000-00000000000f']);
        $game->id = '0193d0aa-0000-7000-8000-00000000000a';

        $campaign = Campaign::factory()->make(['owner_id' => '0193d0aa-0000-7000-8000-00000000000f']);
        $campaign->id = '0193d0aa-0000-7000-8000-00000000000b';

        expect((new ParticipantJoined($participant, $game, 'game'))->batchKey($notifiable))
            ->toBe('participant_joined:0193d0aa-0000-7000-8000-00000000000a')
            ->and((new NewApplication($participant, $game, 'game'))->batchKey($notifiable))
            ->toBe('new_application:0193d0aa-0000-7000-8000-00000000000a')
            ->and((new SessionAddedToCampaign($game, $campaign))->batchKey($notifiable))
            ->toBe('session_added_to_campaign:0193d0aa-0000-7000-8000-00000000000b');
    });

    it('is entity-generic — a campaign join keys to the campaign entity', function () {
        $notifiable = User::factory()->make();
        $participant = User::factory()->make();

        $campaign = Campaign::factory()->make(['owner_id' => '0193d0aa-0000-7000-8000-00000000000f']);
        $campaign->id = '0193d0aa-0000-7000-8000-00000000000c';

        expect((new ParticipantJoined($participant, $campaign, 'campaign'))->batchKey($notifiable))
            ->toBe('participant_joined:0193d0aa-0000-7000-8000-00000000000c');
    });
});
