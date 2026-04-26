<?php

namespace Tests\Feature\Models;

use App\Models\GameSystem;
use App\Models\GameSystemRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameSystemRequestModelTest extends TestCase
{
    use RefreshDatabase;

    // ── Table Structure ────────────────────────────────

    public function test_game_system_requests_table_exists(): void
    {
        $user = User::factory()->create();
        $request = GameSystemRequest::factory()->create(['user_id' => $user->id]);

        $this->assertDatabaseHas('game_system_requests', [
            'id' => $request->id,
        ]);
    }

    public function test_fillable_attributes(): void
    {
        $request = new GameSystemRequest();

        $this->assertEqualsCanonicalizing([
            'user_id', 'name', 'type', 'bgg_url', 'publisher', 'designer',
            'notes', 'status', 'game_system_id', 'reviewed_by', 'rejection_reason',
        ], $request->getFillable());
    }

    // ── Default Values ─────────────────────────────────

    public function test_default_type_is_boardgame(): void
    {
        $request = GameSystemRequest::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Test Game',
        ])->fresh();

        $this->assertEquals('boardgame', $request->type);
    }

    public function test_default_status_is_pending(): void
    {
        $request = GameSystemRequest::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Test Game 2',
        ])->fresh();

        $this->assertEquals('pending', $request->status);
    }

    // ── Scopes ─────────────────────────────────────────

    public function test_scope_pending(): void
    {
        $pending = GameSystemRequest::factory()->create(['status' => 'pending']);
        GameSystemRequest::factory()->create(['status' => 'approved']);

        $results = GameSystemRequest::pending()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($pending));
    }

    public function test_scope_approved(): void
    {
        $approved = GameSystemRequest::factory()->create(['status' => 'approved']);
        GameSystemRequest::factory()->create(['status' => 'pending']);

        $results = GameSystemRequest::approved()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($approved));
    }

    public function test_scope_rejected(): void
    {
        $rejected = GameSystemRequest::factory()->create(['status' => 'rejected']);
        GameSystemRequest::factory()->create(['status' => 'pending']);

        $results = GameSystemRequest::rejected()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($rejected));
    }

    public function test_scope_duplicate(): void
    {
        $dup = GameSystemRequest::factory()->create(['status' => 'duplicate']);
        GameSystemRequest::factory()->create(['status' => 'pending']);

        $results = GameSystemRequest::duplicate()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($dup));
    }

    // ── Relationships ──────────────────────────────────

    public function test_requester_relationship(): void
    {
        $user = User::factory()->create();
        $request = GameSystemRequest::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($request->requester->is($user));
    }

    public function test_reviewer_relationship(): void
    {
        $reviewer = User::factory()->create();
        $request = GameSystemRequest::factory()->create(['reviewed_by' => $reviewer->id]);

        $this->assertTrue($request->reviewer->is($reviewer));
    }

    public function test_game_system_relationship(): void
    {
        $gameSystem = GameSystem::factory()->create();
        $request = GameSystemRequest::factory()->create(['game_system_id' => $gameSystem->id]);

        $this->assertTrue($request->gameSystem->is($gameSystem));
    }

    public function test_game_system_relationship_nullable(): void
    {
        $request = GameSystemRequest::factory()->create(['game_system_id' => null]);

        $this->assertNull($request->gameSystem);
    }
}
