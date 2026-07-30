<?php

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\User;

use function Pest\Laravel\actingAs;

//
// CampaignPolicy smoke tests (M058/S06).
//
// CampaignPolicy view/update/delete authorization was untested (only create
// was covered in GameAndCampaignCreationPolicyTest). These mirror the proven
// GamePolicyVisibilityTest matrix for the core cases.
//

function campaignPolicyCreateOwner(Visibility $visibility = Visibility::Public): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'visibility' => $visibility->value,
    ]);

    return [$owner, $campaign];
}

it('lets the owner view, update, and delete their campaign', function () {
    [$owner, $campaign] = campaignPolicyCreateOwner();

    actingAs($owner);

    expect(Gate::forUser($owner)->allows('view', $campaign))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $campaign))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $campaign))->toBeTrue();
})->group('smoke');

it('lets any user view a public campaign', function () {
    [$owner, $campaign] = campaignPolicyCreateOwner(Visibility::Public);
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $campaign))->toBeTrue();
})->group('smoke');

it('denies a stranger access to a private campaign', function () {
    [$owner, $campaign] = campaignPolicyCreateOwner(Visibility::Private);
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $campaign))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $campaign))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $campaign))->toBeFalse();
})->group('smoke');

it('denies a guest (null user) access to a private campaign', function () {
    [$owner, $campaign] = campaignPolicyCreateOwner(Visibility::Private);

    // Private campaigns require auth.
    expect(Gate::forUser(null)->allows('view', $campaign))->toBeFalse();
})->group('smoke');
