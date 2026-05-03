<?php

namespace Tests\Feature\GM;

use App\Enums\GmProficiency;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesUsers;
use Tests\Traits\SetsUpLocale;

class GMProfileTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesUsers;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    protected function setUp(): void
    {
        $this->setUpLocale();

        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    // ── Slug Generation (non-trivial boot logic) ──────────────

    public function test_slug_generated_from_user_name_on_create(): void
    {
        $user = User::factory()->create(['name' => 'Marcus Stone']);
        $profile = GMProfile::factory()->create(['user_id' => $user->id, 'slug' => null]);

        $this->assertMatchesRegularExpression(
            '/^marcus-stone-[a-zA-Z0-9]{6}$/',
            $profile->slug
        );
    }

    public function test_slug_preserved_when_explicitly_set(): void
    {
        $profile = GMProfile::factory()->create(['slug' => 'custom-gm-slug']);

        $this->assertEquals('custom-gm-slug', $profile->slug);
    }

    public function test_slug_unique_with_random_suffix_for_same_name(): void
    {
        $user1 = User::factory()->create(['name' => 'Alex Rivera']);
        $profile1 = GMProfile::factory()->create(['user_id' => $user1->id]);

        $user2 = User::factory()->create(['name' => 'Alex Rivera']);
        $profile2 = GMProfile::factory()->create(['user_id' => $user2->id]);

        $this->assertNotEquals($profile1->slug, $profile2->slug);
        $this->assertStringStartsWith('alex-rivera-', $profile1->slug);
        $this->assertStringStartsWith('alex-rivera-', $profile2->slug);
    }

    public function test_slug_handles_special_characters(): void
    {
        $user = User::factory()->create(['name' => "O'Brien & Co."]);
        $profile = GMProfile::factory()->create(['user_id' => $user->id, 'slug' => null]);

        $this->assertMatchesRegularExpression('/^obrien-co-[a-zA-Z0-9]{6}$/', $profile->slug);
    }

    public function test_slug_handles_unicode_names(): void
    {
        $user = User::factory()->create(['name' => 'Müller']);
        $profile = GMProfile::factory()->create(['user_id' => $user->id, 'slug' => null]);

        $this->assertMatchesRegularExpression('/^muller-[a-zA-Z0-9]{6}$/', $profile->slug);
    }

    // ── Profile Lifecycle via GmRoleService ────────────────────

    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    public function test_profile_created_on_role_assignment(): void
    {
        $user = $this->createSubscribedUser();
        $service = app(\App\Services\GmRoleService::class);

        $this->assertNull($user->gmProfile);

        $service->assignGMRole($user);

        $profile = $user->fresh()->gmProfile;
        $this->assertNotNull($profile);
        $this->assertTrue($profile->is_active);
        $this->assertEquals($user->id, $profile->user_id);
        $this->assertNotNull($profile->slug);
    }

    public function test_profile_preserved_after_role_revocation(): void
    {
        $user = $this->createSubscribedUser();
        $service = app(\App\Services\GmRoleService::class);

        $service->assignGMRole($user);
        $profileId = $user->gmProfile->id;
        $slug = $user->gmProfile->slug;

        $service->revokeGMRole($user);

        $profile = GMProfile::find($profileId);
        $this->assertNotNull($profile);
        $this->assertFalse($profile->is_active);
        $this->assertEquals($slug, $profile->slug);
    }

    public function test_profile_reactivated_on_role_reassignment(): void
    {
        $user = $this->createSubscribedUser();
        $service = app(\App\Services\GmRoleService::class);

        $service->assignGMRole($user);
        $profileId = $user->gmProfile->id;

        $service->revokeGMRole($user);
        $this->assertFalse($user->gmProfile->fresh()->is_active);

        $service->assignGMRole($user);

        $profile = $user->fresh()->gmProfile;
        $this->assertEquals($profileId, $profile->id);
        $this->assertTrue($profile->is_active);
    }

    public function test_subscription_lapse_preserves_profile_data(): void
    {
        $user = $this->createSubscribedUser();
        $service = app(\App\Services\GmRoleService::class);

        $service->assignGMRole($user);

        $profile = $user->gmProfile;
        $profile->bio = 'Experienced GM specializing in horror campaigns';
        $profile->specializations = ['storytelling', 'sets-the-mood'];
        $profile->save();

        $profileId = $profile->id;

        $service->handleSubscriptionLapse($user);

        $preserved = GMProfile::find($profileId);
        $this->assertNotNull($preserved);
        $this->assertEquals('Experienced GM specializing in horror campaigns', $preserved->bio);
        $this->assertEquals(['storytelling', 'sets-the-mood'], $preserved->specializations);
        $this->assertFalse($preserved->is_active);
    }

    // ── Cascade Delete ────────────────────────────────────────

    public function test_profile_deleted_when_user_deleted(): void
    {
        $profile = GMProfile::factory()->create();
        $profileId = $profile->id;

        $profile->user->delete();

        $this->assertDatabaseMissing('gm_profiles', ['id' => $profileId]);
    }

    // ── Full End-to-End Lifecycle ─────────────────────────────

    public function test_full_gm_lifecycle_with_profile_updates(): void
    {
        $service = app(\App\Services\GmRoleService::class);

        // 1. Assign GM role
        $user = $this->createSubscribedUser(['name' => 'Jane GM']);
        $this->assertTrue($service->assignGMRole($user));

        $profile = $user->fresh()->gmProfile;
        $this->assertNotNull($profile);
        $this->assertTrue($profile->is_active);
        $this->assertStringStartsWith('jane-gm-', $profile->slug);

        // 2. Update profile
        $profile->bio = 'Expert D&D 5e dungeon master';
        $profile->specializations = ['storytelling', 'world-builder', 'rule-of-cool'];
        $profile->save();

        $fresh = $profile->fresh();
        $this->assertEquals('Expert D&D 5e dungeon master', $fresh->bio);
        $this->assertCount(3, $fresh->specializations);
        foreach ($fresh->specializations as $spec) {
            $this->assertNotNull(GmProficiency::tryFrom($spec));
        }

        // 3. Subscription lapses
        $service->handleSubscriptionLapse($user);
        $this->assertFalse($service->isGmActive($user->fresh()));

        // 4. Profile preserved
        $preserved = GMProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($preserved);
        $this->assertFalse($preserved->is_active);
        $this->assertEquals('Expert D&D 5e dungeon master', $preserved->bio);

        // 5. Resubscribe — same profile reactivated
        $service->assignGMRole($user);
        $reactivated = GMProfile::where('user_id', $user->id)->first();
        $this->assertTrue($reactivated->is_active);
        $this->assertEquals($preserved->id, $reactivated->id);
    }
}
