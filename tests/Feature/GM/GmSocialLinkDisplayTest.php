<?php

use App\Livewire\Profile\PublicProfile;
use App\Models\GmSocialLink;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web', 'team_id' => null]);
});

// ── Social links on public GM profile ───────────────────

describe('social links on public profile', function () {
    it('displays social links for a GM with links', function () {
        $gm = $this->createSubscribedGm();

        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'twitter',
            'handle' => 'gmperson',
        ]);

        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'youtube',
            'handle' => 'GMChannel',
        ]);

        Livewire::test(PublicProfile::class, ['user' => $gm])
            ->assertSee('https://x.com/gmperson', false)
            ->assertSee('https://youtube.com/@GMChannel', false);
    });

    it('renders social links with target=_blank', function () {
        $gm = $this->createSubscribedGm();

        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'twitter',
            'handle' => 'iconsgm',
        ]);

        Livewire::test(PublicProfile::class, ['user' => $gm])
            ->assertSee('target="_blank"', false)
            ->assertSee('noopener noreferrer', false);
    });

    it('shows no social links section when GM has no links', function () {
        $gm = $this->createSubscribedGm();

        Livewire::test(PublicProfile::class, ['user' => $gm])
            ->assertDontSee(__('profile.content_find_me_on'));
    });
});

// ── Non-GM profiles ─────────────────────────────────────

describe('non-GM profiles', function () {
    it('does not show social links section on non-GM profile', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee(__('profile.content_find_me_on'))
            ->assertDontSee(__('profile.gm_profile_section_title'));
    });

    it('does not show social links even if orphan links exist without GM profile', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        GmSocialLink::create([
            'user_id' => $user->id,
            'platform' => 'twitter',
            'handle' => 'orphan',
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee('https://x.com/orphan', false);
    });
});

// ── Link ordering ───────────────────────────────────────

describe('link ordering', function () {
    it('displays social links in config sort_order', function () {
        $gm = $this->createSubscribedGm();

        // Create links in reverse order (startplaying=150, twitter=10)
        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'startplaying',
            'handle' => 'z_gm',
        ]);

        GmSocialLink::create([
            'user_id' => $gm->id,
            'platform' => 'twitter',
            'handle' => 'a_gm',
        ]);

        $html = Livewire::test(PublicProfile::class, ['user' => $gm])
            ->html();

        $twitterPos = strpos($html, 'https://x.com/a_gm');
        $startPlayingPos = strpos($html, 'https://startplaying.games/gm/z_gm');

        expect($twitterPos)->not->toBeFalse();
        expect($startPlayingPos)->not->toBeFalse();
        expect($twitterPos)->toBeLessThan($startPlayingPos);
    });
});
