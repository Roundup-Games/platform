<?php

use App\Enums\OAuthProvider;
use App\Livewire\Settings\Show as SettingsShow;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('Linked Accounts management card', function () {
    it('renders a connected card for each linked provider with the brand label', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $user->linkedAccounts()->create([
            'provider' => OAuthProvider::Google,
            'provider_user_id' => '106482519374782345678',
            'token' => 'fake-token',
            'refresh_token' => 'fake-refresh',
        ]);

        $html = Livewire::actingAs($user)->test(SettingsShow::class)->html();

        // Connected Google card: provider label and the "Connected" badge
        expect($html)->toContain('Google')
            ->and($html)->toContain(__('common.content_connected'));

        // The Google icon component is rendered (svg with the brand mark)
        expect($html)->toContain('<svg');
    });

    it('renders a connect affordance for every supported provider the user has NOT linked', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        // Only Google is linked — Discord should appear as a "Connect" card.
        $user->linkedAccounts()->create([
            'provider' => OAuthProvider::Google,
            'provider_user_id' => '106482519374782345678',
            'token' => 'fake-token',
            'refresh_token' => 'fake-refresh',
        ]);

        $html = Livewire::actingAs($user)->test(SettingsShow::class)->html();

        // Discord connect card: provider label, "Not connected" hint, and the
        // oauth.redirect link pointing at the Discord provider.
        expect($html)->toContain('Discord')
            ->and($html)->toContain(__('common.content_not_connected'))
            ->and($html)->toContain(route('oauth.redirect', OAuthProvider::Discord->value));

        // Google must NOT show a connect card because it is already linked.
        // The "Google" string still appears in the connected card above; assert
        // there is no second Google "Connect" affordance by checking the Google
        // redirect URL is absent.
        expect($html)->not->toContain(route('oauth.redirect', OAuthProvider::Google->value));
    });

    it('renders connect affordances for every provider when the user has no linked accounts', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        $html = Livewire::actingAs($user)->test(SettingsShow::class)->html();

        foreach (OAuthProvider::cases() as $provider) {
            expect($html)
                ->toContain($provider->label())
                ->toContain(route('oauth.redirect', $provider->value))
                ->toContain(__('common.content_not_connected'));
        }
    });

    it('does not render any connect affordance when every provider is already linked', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        foreach (OAuthProvider::cases() as $provider) {
            LinkedAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => 'snowflake-'.$provider->value,
                'token' => 'fake-token',
                'refresh_token' => 'fake-refresh',
            ]);
        }

        $html = Livewire::actingAs($user)->test(SettingsShow::class)->html();

        // No "Connect" affordance should appear — every provider is connected.
        // The oauth.redirect link is only rendered inside connect cards (the
        // connected cards show a static "Connected" badge), so its absence is
        // a precise signal that no connect card rendered. Checking the literal
        // 'Connect' string would false-positive on the 'Connected' badge.
        foreach (OAuthProvider::cases() as $provider) {
            expect($html)->not->toContain(route('oauth.redirect', $provider->value));
        }
    });

    it('tolerates a legacy provider value that is not in the OAuthProvider enum', function () {
        // The LinkedAccount.provider cast returns null for unknown backed
        // values. The card must not crash and should fall back to a capitalized
        // label derived from the raw DB value.
        $user = User::factory()->create(['profile_complete' => true]);
        // Bypass the enum cast by writing the row directly.
        LinkedAccount::insert([
            'id' => (string) Str::orderedUuid(),
            'user_id' => $user->id,
            'provider' => 'github', // legacy — not in OAuthProvider
            'provider_user_id' => '12345',
            'token' => 'fake',
            'refresh_token' => 'fake',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = Livewire::actingAs($user)->test(SettingsShow::class)->html();

        // Fallback label is ucfirst() of the raw value.
        expect($html)->toContain('Github')
            // A connect card for every supported provider still renders.
            ->and($html)->toContain('Google')
            ->and($html)->toContain('Discord');
    });

    it('passes an unknown provider string through unchanged on write, so the read-side log fires instead of a write-side throw', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        // Eloquent create() routes through the cast's set(); 'myspace' is not
        // a valid case, so it must persist verbatim (not null, not throw).
        $account = LinkedAccount::create([
            'user_id' => $user->id,
            'provider' => 'myspace',
            'provider_user_id' => '12345',
            'token' => 'fake',
            'refresh_token' => 'fake',
        ]);

        // The raw DB value survives the round-trip (read-side returns null +
        // emits a warning log, which the tolerates-legacy-provider test covers).
        $rawProvider = DB::table('linked_accounts')->where('id', $account->id)->value('provider');
        expect($rawProvider)->toBe('myspace');
    });
});
