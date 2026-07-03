<?php

namespace Tests\Feature\Livewire\Campaigns;

use App\Livewire\Campaigns\CampaignDetail;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature coverage for the CampaignDetail owner-only "plan ahead" nudge banner.
 *
 * The banner is the in-context nudge surface: an owner staring at a thin
 * upcoming-session pipeline sees the nudge right where they would add a
 * session, one click from a pre-filled AddSessionToCampaign form. Gated
 * owner-only, recurring-only, Active-only, and only when fewer than ~2
 * cadence-units of sessions are scheduled ahead.
 *
 * PHPUnit-style class: per MEM755, Pest's blanket DatabaseTransactions does NOT
 * apply to PHPUnit classes, so this class declares the trait explicitly (every
 * case persists a campaign against the Testcontainers PostgreSQL DB).
 *
 * Note on the "non-recurring" negative case: the campaigns.recurrence column is
 * `enum('weekly','bi-weekly','monthly')` NOT NULL, so a persisted campaign can
 * never hold null/empty. That case is exercised via transactional DDL (T03
 * precedent): `ALTER TABLE campaigns ALTER COLUMN recurrence DROP NOT NULL`
 * runs inside the DatabaseTransactions transaction and rolls back cleanly
 * (PostgreSQL DDL is transactional). This exercises the computed's
 * `! $campaign->recurrence` guard end-to-end.
 */
class CampaignDetailNudgeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Render-time __() resolves against the app locale; lock to 'en' so the
        // asserted strings are deterministic regardless of the test app default.
        app()->setLocale('en');
    }

    #[Test]
    public function owner_sees_nudge_for_thin_upcoming_session_pipeline(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'weekly',
            'status' => 'active',
        ]);

        // No upcoming scheduled sessions => nudge should fire.
        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Plan ahead')
            ->assertSee('Plan next session')
            ->assertSee('prefill=1')
            ->assertSet('planAheadNudge', function (?array $nudge): bool {
                return $nudge !== null
                    && str_contains($nudge['action_url'], 'prefill=1');
            });
    }

    #[Test]
    public function nudge_hidden_from_non_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'weekly',
            'status' => 'active',
        ]);

        // A participant/visitor viewing the same (public) campaign must not see
        // the owner-only nudge. mount() only authorizes 'view'.
        Livewire::actingAs($otherUser)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Plan ahead')
            ->assertDontSee('Plan next session')
            ->assertSet('planAheadNudge', null);
    }

    #[Test]
    public function nudge_absent_when_upcoming_horizon_is_healthy(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'weekly',
            'status' => 'active',
        ]);

        // A scheduled session 20 days out is beyond the weekly 14-day horizon
        // (2 cadence-units), so the pipeline is healthy and no nudge fires.
        Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(20),
        ]);

        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Plan ahead')
            ->assertDontSee('Plan next session')
            ->assertSet('planAheadNudge', null);
    }

    #[Test]
    public function nudge_absent_for_non_recurring_campaign(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'weekly',
            'status' => 'active',
        ]);

        // campaigns.recurrence is enum NOT NULL; drop the constraint inside the
        // DatabaseTransactions transaction so it rolls back cleanly (T03
        // precedent). PostgreSQL DDL is transactional; NULL also bypasses any
        // CHECK constraint. This exercises the computed's recurrence guard.
        DB::statement('ALTER TABLE campaigns ALTER COLUMN recurrence DROP NOT NULL');
        $campaign->update(['recurrence' => null]);

        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Plan ahead')
            ->assertDontSee('Plan next session')
            ->assertSet('planAheadNudge', null);
    }

    #[Test]
    public function nudge_absent_for_completed_campaign(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'recurrence' => 'weekly',
            'status' => 'completed',
        ]);

        // Completed campaigns never nudge (RecurrenceService::shouldNudge gates
        // on Active status).
        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Plan ahead')
            ->assertDontSee('Plan next session')
            ->assertSet('planAheadNudge', null);
    }
}
