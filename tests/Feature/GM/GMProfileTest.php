<?php

namespace Tests\Feature\GM;

use App\Enums\GmProficiency;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GMProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    // ── Helpers ────────────────────────────────────────

    private function createSubscribedUser(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);

        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_' . Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);

        return $user;
    }

    // ── Specialization Storage & Retrieval ─────────────

    public function test_specializations_stored_as_json_array(): void
    {
        $specs = ['storytelling', 'world-builder', 'voices'];
        $profile = GMProfile::factory()->create(['specializations' => $specs]);

        $profile->refresh();
        $this->assertIsArray($profile->specializations);
        $this->assertEquals($specs, $profile->specializations);
    }

    public function test_specializations_can_be_empty_array(): void
    {
        $profile = GMProfile::factory()->create(['specializations' => []]);

        $profile->refresh();
        $this->assertIsArray($profile->specializations);
        $this->assertEquals([], $profile->specializations);
    }

    public function test_specializations_can_be_null(): void
    {
        $profile = GMProfile::factory()->create(['specializations' => null]);

        $profile->refresh();
        $this->assertNull($profile->specializations);
    }

    public function test_specializations_contain_valid_gm_proficiency_values(): void
    {
        $validValues = GmProficiency::values();
        $profile = GMProfile::factory()->create([
            'specializations' => $validValues,
        ]);

        $profile->refresh();
        $this->assertCount(10, $profile->specializations);
        foreach ($profile->specializations as $spec) {
            $this->assertNotNull(
                GmProficiency::tryFrom($spec),
                "'{$spec}' should be a valid GmProficiency value"
            );
        }
    }

    public function test_specialization_roundtrip_with_enum(): void
    {
        $original = [
            GmProficiency::Creativity->value,
            GmProficiency::Storytelling->value,
            GmProficiency::WorldBuilder->value,
        ];
        $profile = GMProfile::factory()->create(['specializations' => $original]);

        $profile->refresh();
        $retrieved = $profile->specializations;

        // Each stored value resolves back to the enum case
        foreach ($retrieved as $value) {
            $enum = GmProficiency::tryFrom($value);
            $this->assertNotNull($enum);
            $this->assertNotEmpty($enum->label());
        }
    }

    // ── Slug Generation Integration ────────────────────

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

        // Str::slug handles transliteration
        $this->assertMatchesRegularExpression('/^muller-[a-zA-Z0-9]{6}$/', $profile->slug);
    }

    // ── Profile Lifecycle via GmRoleService ────────────

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

        // First assignment
        $service->assignGMRole($user);
        $profileId = $user->gmProfile->id;

        // Revoke
        $service->revokeGMRole($user);
        $this->assertFalse($user->gmProfile->fresh()->is_active);

        // Re-assign (simulate resubscribe)
        $service->assignGMRole($user);

        // Should be same profile, reactivated
        $profile = $user->fresh()->gmProfile;
        $this->assertEquals($profileId, $profile->id);
        $this->assertTrue($profile->is_active);
    }

    public function test_subscription_lapse_preserves_profile_data(): void
    {
        $user = $this->createSubscribedUser();
        $service = app(\App\Services\GmRoleService::class);

        $service->assignGMRole($user);

        // Update bio and specializations
        $profile = $user->gmProfile;
        $profile->bio = 'Experienced GM specializing in horror campaigns';
        $profile->specializations = ['storytelling', 'sets-the-mood'];
        $profile->save();

        $profileId = $profile->id;

        // Simulate subscription lapse
        $service->handleSubscriptionLapse($user);

        // Profile still exists with all data
        $preserved = GMProfile::find($profileId);
        $this->assertNotNull($preserved);
        $this->assertEquals('Experienced GM specializing in horror campaigns', $preserved->bio);
        $this->assertEquals(['storytelling', 'sets-the-mood'], $preserved->specializations);
        $this->assertFalse($preserved->is_active);
    }

    // ── Profile Update Integration ─────────────────────

    public function test_bio_can_be_updated(): void
    {
        $profile = GMProfile::factory()->create(['bio' => null]);

        $profile->bio = 'Updated bio text';
        $profile->save();

        $this->assertEquals('Updated bio text', $profile->fresh()->bio);
    }

    public function test_specializations_can_be_updated(): void
    {
        $profile = GMProfile::factory()->create(['specializations' => null]);

        $profile->specializations = ['creativity', 'teacher'];
        $profile->save();

        $this->assertEquals(['creativity', 'teacher'], $profile->fresh()->specializations);
    }

    public function test_rating_and_review_count_update(): void
    {
        $profile = GMProfile::factory()->create([
            'average_rating' => null,
            'review_count' => 0,
        ]);

        $profile->average_rating = 4.50;
        $profile->review_count = 5;
        $profile->save();

        $fresh = $profile->fresh();
        $this->assertEquals('4.50', $fresh->average_rating);
        $this->assertEquals(5, $fresh->review_count);
    }

    // ── Cascade Delete ─────────────────────────────────

    public function test_profile_deleted_when_user_deleted(): void
    {
        $profile = GMProfile::factory()->create();
        $profileId = $profile->id;
        $userId = $profile->user_id;

        $profile->user->delete();

        $this->assertDatabaseMissing('gm_profiles', ['id' => $profileId]);
    }

    // ── User Relationship Integration ──────────────────

    public function test_user_is_gm_returns_true_with_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        $this->assertTrue($user->isGM());
    }

    public function test_user_is_gm_returns_false_without_role(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isGM());
    }

    public function test_user_gm_profile_relationship(): void
    {
        $user = User::factory()->create();
        $profile = GMProfile::factory()->create(['user_id' => $user->id]);

        $user->refresh();
        $this->assertTrue($user->gmProfile->is($profile));
    }

    public function test_user_without_gm_profile_returns_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->gmProfile);
    }

    // ── Factory Integration ────────────────────────────

    public function test_factory_creates_valid_profile_with_specializations(): void
    {
        $profile = GMProfile::factory()->create([
            'specializations' => ['creativity', 'inclusive', 'knows-the-rules'],
        ]);

        $this->assertIsArray($profile->specializations);
        $this->assertCount(3, $profile->specializations);
        $this->assertTrue($profile->is_active);
        $this->assertNotNull($profile->slug);
    }

    public function test_factory_auto_generates_slug_from_user(): void
    {
        $user = User::factory()->create(['name' => 'Test GM User']);
        $profile = GMProfile::factory()->create(['user_id' => $user->id]);

        $this->assertStringStartsWith('test-gm-user-', $profile->slug);
    }

    // ── Full End-to-End Lifecycle ──────────────────────

    public function test_full_gm_lifecycle_with_profile_updates(): void
    {
        $service = app(\App\Services\GmRoleService::class);

        // 1. User subscribes and becomes GM
        $user = $this->createSubscribedUser(['name' => 'Jane GM']);
        $this->assertTrue($service->assignGMRole($user));

        $profile = $user->fresh()->gmProfile;
        $this->assertNotNull($profile);
        $this->assertTrue($profile->is_active);
        $this->assertStringStartsWith('jane-gm-', $profile->slug);

        // 2. User updates their GM profile
        $profile->bio = 'Expert D&D 5e dungeon master';
        $profile->specializations = ['storytelling', 'world-builder', 'rule-of-cool'];
        $profile->save();

        // 3. Verify data persisted
        $fresh = $profile->fresh();
        $this->assertEquals('Expert D&D 5e dungeon master', $fresh->bio);
        $this->assertCount(3, $fresh->specializations);
        foreach ($fresh->specializations as $spec) {
            $this->assertNotNull(GmProficiency::tryFrom($spec));
        }

        // 4. Subscription lapses
        $service->handleSubscriptionLapse($user);
        $this->assertFalse($service->isGmActive($user->fresh()));

        // 5. Profile preserved with data
        $preserved = GMProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($preserved);
        $this->assertFalse($preserved->is_active);
        $this->assertEquals('Expert D&D 5e dungeon master', $preserved->bio);
        $this->assertCount(3, $preserved->specializations);

        // 6. User resubscribes
        $service->assignGMRole($user);
        $reactivated = GMProfile::where('user_id', $user->id)->first();
        $this->assertTrue($reactivated->is_active);
        $this->assertEquals($preserved->id, $reactivated->id);
    }
}
