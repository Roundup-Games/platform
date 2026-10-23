<?php

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Notifications\CharterAmended;
use App\Notifications\CharterPublished;
use App\Services\Charter\CharterService;
use Illuminate\Support\Facades\Notification;

//
// Charter lifecycle notifications (M070 concept §3/§4, D234 closure).
//
// The re-agreement engine was complete since S03, but nothing told a
// participant it was their turn — the campaign-page card and out-of-band
// contact were the only discovery. These tests pin the shipped loop:
// publish and amendment fan notifications out through NotificationService
// to the same audience the sign gate admits (approved participants,
// owner excluded), after the lifecycle transaction commits.
//

/**
 * Campaign owner + campaign with deterministic quick-start inputs.
 *
 * @return array{0: User, 1: Campaign}
 */
function charter_notify_owner_campaign(): array
{
    $owner = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);

    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'description' => ['en' => 'A noir intrigue campaign in the city of Threshold.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);

    return [$owner, $campaign];
}

/**
 * An approved campaign participant.
 */
function charter_notify_participant(Campaign $campaign): User
{
    $participant = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);

    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $participant->id,
        'status' => 'approved',
    ]);

    return $participant;
}

/**
 * Publish the campaign's charter from quick-start content.
 *
 * @return array{0: SessionZeroSurvey, 1: array<string, mixed>}
 */
function charter_notify_published(User $owner, Campaign $campaign): array
{
    $charter = CharterService::start($owner, $campaign, 'quickstart');
    $content = CharterContent::quickStart($campaign)->toArray();

    CharterService::publish($owner, $charter, 'The charter', $content);

    return [$charter, $content];
}

test('publishing a charter notifies approved participants but never the owner or pending applicants', function () {
    Notification::fake();
    [$owner, $campaign] = charter_notify_owner_campaign();
    $participant = charter_notify_participant($campaign);

    $pending = User::factory()->create(['profile_complete' => true]);
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $pending->id,
        'status' => 'pending',
    ]);

    charter_notify_published($owner, $campaign);

    Notification::assertSentTo($participant, CharterPublished::class);
    Notification::assertNotSentTo($owner, CharterPublished::class);
    Notification::assertNotSentTo($pending, CharterPublished::class);
});

test('a material amendment asks approved participants to review and re-sign', function () {
    Notification::fake();
    [$owner, $campaign] = charter_notify_owner_campaign();
    $participant = charter_notify_participant($campaign);
    [$charter, $content] = charter_notify_published($owner, $campaign);

    // Material by definition: the §2 table standard changed.
    $content['boundaries']['lines_categories'] = ['violence'];

    CharterService::amend(
        $owner,
        $charter,
        'The charter',
        $content,
        'Tightened the lines after our session zero talk.',
        (int) $charter->current_version,
    );

    Notification::assertSentTo(
        $participant,
        CharterAmended::class,
        fn (CharterAmended $notification) => $notification->material === true,
    );
});

test('a minor amendment is informational and carries signatures', function () {
    Notification::fake();
    [$owner, $campaign] = charter_notify_owner_campaign();
    $participant = charter_notify_participant($campaign);
    [$charter, $content] = charter_notify_published($owner, $campaign);

    // Minor by definition: a §6 logistics tweak.
    $content['logistics']['session_length'] = '4h';

    CharterService::amend(
        $owner,
        $charter,
        'The charter',
        $content,
        'Sessions now run four hours.',
        (int) $charter->current_version,
    );

    Notification::assertSentTo(
        $participant,
        CharterAmended::class,
        fn (CharterAmended $notification) => $notification->material === false,
    );
});

test('drafts and saves never notify anyone', function () {
    Notification::fake();
    [$owner, $campaign] = charter_notify_owner_campaign();
    charter_notify_participant($campaign);

    $charter = CharterService::start($owner, $campaign, 'quickstart');
    $content = CharterContent::quickStart($campaign)->toArray();

    CharterService::saveDraft($owner, $charter, 'The charter', $content);

    Notification::assertNothingSent();
});
