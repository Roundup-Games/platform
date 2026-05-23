<?php

use App\Livewire\Profile\Show;
use App\Models\GmSocialLink;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web', 'team_id' => null]);
});

// ── GM Profile Tab Visibility ───────────────────────────

describe('GM Profile tab visibility', function () {
    it('shows GM Profile tab to GM users', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSeeHtml('gm_profile')
            ->assertSeeHtml(__('profile.field_gm_profile'));
    });

    it('hides GM Profile tab from non-GM users', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->assertDontSeeHtml('gm_profile')
            ->assertDontSeeHtml(__('profile.field_gm_profile'));
    });
});

// ── Social Links Form Rendering ─────────────────────────

describe('social links form rendering', function () {
    it('renders platform inputs for GMs', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSeeHtml('social-twitter')
            ->assertSeeHtml('social-youtube')
            ->assertSeeHtml('social-instagram');
    });

    it('pre-fills existing social link handles', function () {
        $gm = $this->createSubscribedGm();

        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'twitter',
            'handle' => 'existing_handle',
        ]);

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSet('socialLinks.twitter.handle', 'existing_handle');
    });

    it('initializes empty handles for platforms without links', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->assertSet('socialLinks.twitter.handle', '')
            ->assertSet('socialLinks.youtube.handle', '');
    });
});

// ── Saving Social Links ─────────────────────────────────

describe('saving social links', function () {
    it('sets socialLinksSaved flag on successful save', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->set('socialLinks.twitter.handle', 'my_twitter')
            ->set('socialLinks.youtube.handle', 'MyChannel')
            ->call('saveSocialLinks')
            ->assertSet('socialLinksSaved', true);
    });

    it('does nothing for non-GM users', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        $component = Livewire::actingAs($user)
            ->test(Show::class)
            ->call('saveSocialLinks');

        // socialLinksSaved should remain false
        $component->assertSet('socialLinksSaved', false);
    });

    it('validates handle max length', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->set('socialLinks.twitter.handle', str_repeat('a', 256))
            ->call('saveSocialLinks')
            ->assertHasErrors(['socialLinks.twitter.handle']);
    });

    it('validates instance max length for Mastodon', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->set('socialLinks.mastodon.handle', 'validuser')
            ->set('socialLinks.mastodon.instance', str_repeat('b', 256))
            ->call('saveSocialLinks')
            ->assertHasErrors(['socialLinks.mastodon.instance']);
    });

    it('accepts empty handles to clear links', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(Show::class)
            ->set('socialLinks.twitter.handle', '')
            ->call('saveSocialLinks')
            ->assertSet('socialLinksSaved', true)
            ->assertHasNoErrors();
    });
});
