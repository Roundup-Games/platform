<?php

use App\Dto\CharterContent;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Notifications\CharterAmended;
use App\Notifications\CharterFullySigned;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Illuminate\Support\Facades\Notification;

//
// "Every participant has signed" → the GM (M070 follow-up: the all-signed
// GM notification).
//
// The GM's countersignature is only allowed once every approved participant
// has signed the current version, and until this notification the GM could
// discover that moment only by polling the workspace roster. These tests pin
// the trigger: it fires on the signature that COMPLETES the roster, per
// version, to the owner — and stays silent in every state where nothing is
// owed.
//

/**
 * Campaign owner + campaign with deterministic quick-start inputs.
 *
 * @return array{0: User, 1: Campaign}
 */
function fully_signed_owner_campaign(): array
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
function fully_signed_participant(Campaign $campaign): User
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
function fully_signed_published(User $owner, Campaign $campaign): array
{
    $charter = CharterService::start($owner, $campaign, 'quickstart');
    $content = CharterContent::quickStart($campaign)->toArray();

    CharterService::publish($owner, $charter, 'The charter', $content);

    return [$charter, $content];
}

/**
 * Sign the charter's current version with every §8 item agreed.
 */
function fully_signed_sign(User $participant, SessionZeroSurvey $charter): void
{
    CharterSigningService::sign($participant, $charter, [
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
    ]);
}

test('the signature that completes the roster notifies the owner, earlier signatures do not', function () {
    Notification::fake();
    [$owner, $campaign] = fully_signed_owner_campaign();
    [$charter] = fully_signed_published($owner, $campaign);
    $first = fully_signed_participant($campaign);
    $last = fully_signed_participant($campaign);

    fully_signed_sign($first, $charter);
    Notification::assertNotSentTo($owner, CharterFullySigned::class);

    fully_signed_sign($last, $charter);

    Notification::assertSentTo(
        $owner,
        CharterFullySigned::class,
        fn (CharterFullySigned $notification) => $notification->signer->is($last)
            && $notification->campaign->is($campaign)
            && $notification->version->is($charter->currentVersion()),
    );
});

test('the owner learns of a completed roster again after a material amendment resets the signatures', function () {
    Notification::fake();
    [$owner, $campaign] = fully_signed_owner_campaign();
    [$charter, $content] = fully_signed_published($owner, $campaign);
    $participant = fully_signed_participant($campaign);

    fully_signed_sign($participant, $charter);
    Notification::assertCount(1);

    $content['boundaries']['lines_categories'] = ['violence'];

    CharterService::amend(
        $owner,
        $charter,
        'The charter',
        $content,
        'Tightened the lines after our session zero talk.',
        (int) $charter->current_version,
    );

    // A material amendment fans CharterAmended out to the participant; the
    // owner's fully-signed notice must not re-fire until the roster
    // completes on the NEW version.
    Notification::assertSentTo($participant, CharterAmended::class);
    Notification::assertSentTo($owner, CharterFullySigned::class, 1);

    fully_signed_sign($participant, $charter->fresh());

    // Once for the original version, once for the re-signed one.
    Notification::assertSentTo(
        $owner,
        CharterFullySigned::class,
        fn (CharterFullySigned $notification) => $notification->version->version_number === 2,
    );
});

test('an owner who is the completing signer is not told about their own action', function () {
    Notification::fake();
    [$owner, $campaign] = fully_signed_owner_campaign();
    [$charter] = fully_signed_published($owner, $campaign);

    // The owner as the campaign's only approved participant.
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'status' => 'approved',
    ]);

    fully_signed_sign($owner, $charter);

    Notification::assertNotSentTo($owner, CharterFullySigned::class);
});

test('the stored payload carries the signer, the campaign and the charter deep link', function () {
    [$owner, $campaign] = fully_signed_owner_campaign();
    [$charter] = fully_signed_published($owner, $campaign);
    $participant = fully_signed_participant($campaign);

    fully_signed_sign($participant, $charter);

    $row = $owner->fresh()->notifications()
        ->where('type', CharterFullySigned::class)
        ->firstOrFail();

    expect($row->data)->toMatchArray([
        'type' => 'charter_fully_signed',
        'entity_type' => 'campaign',
        'entity_id' => $campaign->id,
        'entity_name' => $campaign->name,
        'signer_id' => $participant->id,
        'signer_name' => $participant->name,
        'charter_uuid' => $charter->uuid,
    ])
        ->and($row->data['action_url'])->toBe(route('charter.view', ['locale' => 'en', 'uuid' => $charter->uuid]));
});

test('an owner who blocked the completing signer is left alone', function () {
    [$owner, $campaign] = fully_signed_owner_campaign();
    [$charter] = fully_signed_published($owner, $campaign);
    $participant = fully_signed_participant($campaign);

    $owner->blocks()->create([
        'related_user_id' => $participant->id,
        'type' => RelationshipType::Block,
    ]);

    fully_signed_sign($participant, $charter);

    expect($owner->fresh()->notifications()->where('type', CharterFullySigned::class)->exists())->toBeFalse();
});
