<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserVibePreference;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class UserVibePreferenceTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    // ── Composite PK uniqueness (business rule: one preference type per vibe flag per user) ──

    public function test_composite_pk_enforces_uniqueness(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'favorite',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'avoid',
        ]);
    }

    public function test_different_users_can_have_same_vibe_preference(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user1->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'favorite',
        ]);

        $pref2 = UserVibePreference::create([
            'user_id' => $user2->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'favorite',
        ]);

        $this->assertNotNull($pref2);
        $this->assertDatabaseCount('user_vibe_preferences', 2);
    }

    // ── Cascade on delete (data integrity) ──────────────────

    public function test_vibe_preferences_deleted_when_user_deleted(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'exploration',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'horror',
            'preference_type' => 'avoid',
        ]);

        $this->assertDatabaseCount('user_vibe_preferences', 2);

        $user->delete();

        $this->assertDatabaseCount('user_vibe_preferences', 0);
    }
}
