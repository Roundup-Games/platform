<?php

namespace Tests\Feature\Livewire\Campaigns;

use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Models\Campaign;
use App\Models\User;
use App\Services\RecurrenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature coverage for the AddSessionToCampaign pre-fill intake.
 *
 * Deep-linked "plan ahead" nudge CTAs hit `/campaigns/{id}/add-session?prefill=1`.
 * mount() honours that flag by pre-filling `date_time` from RecurrenceService's
 * next suggested cadence date — and ONLY date/time (the host still names the
 * session).
 *
 * PHPUnit-style class: per MEM755, Pest's blanket DatabaseTransactions does NOT
 * apply to PHPUnit classes, so this class declares the trait explicitly (every
 * case persists a campaign against the Testcontainers PostgreSQL DB).
 *
 * Note on the "non-recurring" defensive branch: the campaigns.recurrence column
 * is `enum('weekly','bi-weekly','monthly')` NOT NULL, so a persisted campaign
 * can never hold a null/empty/'custom' recurrence. The `$campaign->recurrence`
 * truthiness guard and the `if ($suggested)` guard are therefore defensive
 * (future-proofing against a schema change) and the unreachable null path is
 * already covered by RecurrenceService's unit tests (T01:
 * compute_next_date_returns_null_for_unknown_recurrence). This feature test
 * exercises every state achievable with real persisted data.
 */
class AddSessionToCampaignPrefillTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function prefill_flag_populates_date_time_for_recurring_campaign(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
        ]);

        // Compute the expected value through the same service the component
        // uses, so the assertion is independent of the literal +7-day math.
        $expected = app(RecurrenceService::class)
            ->nextSuggestedDateTime($campaign)
            ->format('Y-m-d\TH:i');

        Livewire::actingAs($owner)
            ->withQueryParams(['prefill' => '1'])
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->assertSet('date_time', $expected);
    }

    #[Test]
    public function date_time_stays_empty_without_prefill_flag(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
        ]);

        Livewire::actingAs($owner)
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->assertSet('date_time', '');
    }

    #[Test]
    public function only_date_time_is_prefilled_and_cadence_is_honoured(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'bi-weekly',
            'time_of_day' => '18:30',
        ]);

        // Bi-weekly (+14 days) proves the pre-fill flows through
        // RecurrenceService rather than a hardcoded weekly offset, and the
        // name/description/location_details assertions lock the date-only
        // pre-fill contract.
        $expected = app(RecurrenceService::class)
            ->nextSuggestedDateTime($campaign)
            ->format('Y-m-d\TH:i');

        Livewire::actingAs($owner)
            ->withQueryParams(['prefill' => '1'])
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->assertSet('date_time', $expected)
            ->assertSet('name', '')
            ->assertSet('description', '')
            ->assertSet('location_details', '');
    }
}
