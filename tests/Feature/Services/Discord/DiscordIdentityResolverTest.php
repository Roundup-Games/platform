<?php

namespace Tests\Feature\Services\Discord;

use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Discord\DiscordIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @covers \App\Services\Discord\DiscordIdentityResolver
 *
 * The identity bridge for Discord button RSVPs (M057/S03/T02): a clicker's
 * Discord member snowflake resolves to the roundup User who owns the linked
 * Discord account, or null when the clicker is unlinked. A null result forks
 * the controller into the ephemeral deep-link branch — NO participant write —
 * so the resolver's null contract is load-bearing and tested exhaustively.
 */
class DiscordIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private DiscordIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DiscordIdentityResolver;
    }

    #[Test]
    public function it_resolves_a_linked_discord_member_to_their_roundup_user(): void
    {
        $user = User::factory()->create();
        $snowflake = '111222333444555666';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => $snowflake,
        ]);

        $resolved = $this->resolver->resolveBySnowflake($snowflake);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($user));
    }

    #[Test]
    public function it_returns_null_for_an_unlinked_member(): void
    {
        // No linked account exists for this snowflake — the clicker has never
        // linked Discord. Controller forks to the ephemeral deep-link.
        $resolved = $this->resolver->resolveBySnowflake('999999999999999999');

        $this->assertNull($resolved);
    }

    #[Test]
    public function it_does_not_match_a_non_discord_provider_with_the_same_snowflake(): void
    {
        // A Google linked account sharing the same provider_user_id string must
        // NOT resolve — the provider filter is part of the lookup contract, so
        // a Google-only user clicking the Discord button is treated as unlinked.
        $user = User::factory()->create();
        $snowflake = '777888999000111222';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Google->value,
            'provider_user_id' => $snowflake,
        ]);

        $resolved = $this->resolver->resolveBySnowflake($snowflake);

        $this->assertNull($resolved);
    }

    #[Test]
    public function it_returns_null_for_an_empty_snowflake(): void
    {
        // A malformed interaction (missing member.user.id) must never match a
        // theoretically-empty provider_user_id row — short-circuit before query.
        $this->assertNull($this->resolver->resolveBySnowflake(''));
        $this->assertNull($this->resolver->resolveBySnowflake('   '));
    }

    #[Test]
    public function it_only_matches_the_exact_snowflake_not_a_prefix(): void
    {
        $user = User::factory()->create();
        $snowflake = '111222333444555666';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => $snowflake,
        ]);

        // A different snowflake (even one that shares a prefix) must NOT match.
        $this->assertNull($this->resolver->resolveBySnowflake('111222333444555000'));
        $this->assertNull($this->resolver->resolveBySnowflake('1112223334445556667'));
    }

    #[Test]
    public function it_uses_the_o_auth_controller_lookup_precedent_where_provider_first(): void
    {
        // Mirrors OAuthController::callback()'s `LinkedAccount::where('provider')
        // ->where('provider_user_id')->first()` shape. Two linked accounts for
        // the same user (one Discord, one Google) resolve only on the Discord one.
        $user = User::factory()->create();
        $snowflake = '444555666777888999';
        $googleId = 'google-user-42';

        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Google->value,
            'provider_user_id' => $googleId,
        ]);
        LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => $snowflake,
        ]);

        $resolved = $this->resolver->resolveBySnowflake($snowflake);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($user));
    }
}
