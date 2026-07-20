<?php

use App\Livewire\Profile\Show;
use App\Models\GmSocialLink;
use App\Models\LinkedAccount;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Traits\CreatesUsers;

/**
 * M056/S03/T11: Auto-fill GM Discord social link from linked Discord account
 * on profile form mount.
 *
 * Tests pin the invariant: existing GmSocialLink handle takes precedence over
 * auto-fill, and the auto-fill fires only for GMs with a linked Discord account.
 * Sibling concerns (manual 'Use my Discord' re-apply, GM/non-GM visibility) are
 * covered by GmSocialLinkUiTest; this file is scoped to the T11 mount behavior.
 */
uses(CreatesUsers::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web', 'team_id' => null]);
});

// ── Discord auto-fill on mount ──────────────────────────

describe('Discord handle auto-fill on profile mount', function () {
    it('auto-fills the Discord handle when the GM has a linked Discord account and no existing GmSocialLink', function () {
        $gm = $this->createSubscribedGm();

        LinkedAccount::factory()->create([
            'user_id' => $gm->id,
            'provider' => 'discord',
            'provider_user_id' => '234567890123456789',
        ]);

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSet('socialLinks.discord.handle', '234567890123456789') // gitleaks:allow — synthetic test snowflake
            ->assertSet('discordLinkedUserId', '234567890123456789') // gitleaks:allow — synthetic test snowflake
            ->assertSet('discordAutofilled', true);
    });

    it('keeps the existing GmSocialLink handle and does NOT auto-fill when one already exists', function () {
        $gm = $this->createSubscribedGm();

        // The GM has previously saved a Discord handle (manual or legacy)
        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'discord',
            'handle' => '999888777666555444',
        ]);

        LinkedAccount::factory()->create([
            'user_id' => $gm->id,
            'provider' => 'discord',
            'provider_user_id' => '111222333444555666',
        ]);

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSet('socialLinks.discord.handle', '999888777666555444') // gitleaks:allow — synthetic test snowflake
            ->assertSet('discordLinkedUserId', '111222333444555666') // gitleaks:allow — synthetic test snowflake
            ->assertSet('discordAutofilled', false);
    });

    it('leaves the Discord handle empty when the GM has no linked Discord account', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSet('socialLinks.discord.handle', '')
            ->assertSet('discordLinkedUserId', null)
            ->assertSet('discordAutofilled', false);
    });

    it('renders the auto-filled info hint when the Discord handle was auto-populated', function () {
        $gm = $this->createSubscribedGm();

        LinkedAccount::factory()->create([
            'user_id' => $gm->id,
            'provider' => 'discord',
            'provider_user_id' => '345678901234567890',
        ]);

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSeeHtml(__('profile.gm_social_discord_autofilled'));
    });

    it('does not render the auto-filled info hint when an existing GmSocialLink handle was preserved', function () {
        $gm = $this->createSubscribedGm();

        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'discord',
            'handle' => '998877665544332211',
        ]);

        LinkedAccount::factory()->create([
            'user_id' => $gm->id,
            'provider' => 'discord',
            'provider_user_id' => '112233445566778899',
        ]);

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertDontSeeHtml(__('profile.gm_social_discord_autofilled'));
    });
});

// ── Non-GM isolation ────────────────────────────────────

describe('non-GM users', function () {
    it('does not auto-fill the Discord handle even with a linked Discord account', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => '456789012345678901',
        ]);

        // Non-GM mount path skips social-link resolution entirely
        Livewire::actingAs($user)
            ->test(Show::class)
            ->assertSet('discordLinkedUserId', null)
            ->assertSet('discordAutofilled', false);
    });
});

// ── 'Use my Discord' remains a re-apply affordance ──────

describe('Use my Discord re-apply after manual edit', function () {
    it('clearing a manually-edited handle then clicking Use my Discord reapplies the linked ID', function () {
        $gm = $this->createSubscribedGm();

        LinkedAccount::factory()->create([
            'user_id' => $gm->id,
            'provider' => 'discord',
            'provider_user_id' => '567890123456789012',
        ]);

        $component = Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSet('socialLinks.discord.handle', '567890123456789012'); // gitleaks:allow — synthetic test snowflake (auto-filled on mount)

        // GM manually overrides the handle, then decides to restore
        $component
            ->set('socialLinks.discord.handle', 'manual_handle')
            ->call('useMyDiscord')
            ->assertSet('socialLinks.discord.handle', '567890123456789012'); // gitleaks:allow — synthetic test snowflake
    });

    it('Use my Discord is a no-op when the GM has no linked Discord account', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->set('socialLinks.discord.handle', 'whatever')
            ->call('useMyDiscord')
            ->assertSet('socialLinks.discord.handle', 'whatever');
    });
});
