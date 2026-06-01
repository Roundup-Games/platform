<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GameBulletinModelTest extends TestCase
{
    use DatabaseTransactions;

    // ── UUID Auto-Generation ──────────────────────────────────

    public function test_uuid_auto_generated_on_create(): void
    {
        $bulletin = GameBulletin::factory()->create();

        $this->assertNotNull($bulletin->id);
        $this->assertIsString($bulletin->id);
        $this->assertEquals(36, strlen($bulletin->id));
    }

    // ── Relationships ─────────────────────────────────────────

    public function test_belongs_to_game(): void
    {
        $bulletin = GameBulletin::factory()->create();

        $this->assertInstanceOf(Game::class, $bulletin->game);
        $this->assertEquals($bulletin->game_id, $bulletin->game->id);
    }

    public function test_belongs_to_user(): void
    {
        $bulletin = GameBulletin::factory()->create();

        $this->assertInstanceOf(User::class, $bulletin->user);
        $this->assertEquals($bulletin->user_id, $bulletin->user->id);
    }

    public function test_game_has_bulletins_relationship(): void
    {
        $game = Game::factory()->create();
        GameBulletin::factory()->count(3)->create(['game_id' => $game->id]);

        $this->assertCount(3, $game->bulletins);
    }

    // ── Scopes ────────────────────────────────────────────────

    public function test_not_expired_scope_includes_null_expires_at(): void
    {
        $bulletin = GameBulletin::factory()->create(['expires_at' => null]);

        $results = GameBulletin::notExpired()->get();

        $this->assertTrue($results->contains($bulletin));
    }

    public function test_not_expired_scope_includes_future_expires_at(): void
    {
        $bulletin = GameBulletin::factory()->create(['expires_at' => now()->addHour()]);

        $results = GameBulletin::notExpired()->get();

        $this->assertTrue($results->contains($bulletin));
    }

    public function test_not_expired_scope_excludes_past_expires_at(): void
    {
        $bulletin = GameBulletin::factory()->create(['expires_at' => now()->subHour()]);

        $results = GameBulletin::notExpired()->get();

        $this->assertFalse($results->contains($bulletin));
    }

    // ── Accessors ─────────────────────────────────────────────

    public function test_is_expired_returns_false_when_no_expiry(): void
    {
        $bulletin = GameBulletin::factory()->create(['expires_at' => null]);

        $this->assertFalse($bulletin->is_expired);
    }

    public function test_is_expired_returns_false_when_future_expiry(): void
    {
        $bulletin = GameBulletin::factory()->create(['expires_at' => now()->addHour()]);

        $this->assertFalse($bulletin->is_expired);
    }

    public function test_is_expired_returns_true_when_past_expiry(): void
    {
        $bulletin = GameBulletin::factory()->create(['expires_at' => now()->subHour()]);

        $this->assertTrue($bulletin->is_expired);
    }

    // ── Content Length ────────────────────────────────────────

    public function test_content_is_limited_to_280_chars(): void
    {
        $bulletin = GameBulletin::factory()->create(['content' => str_repeat('a', 280)]);

        $this->assertEquals(280, strlen($bulletin->content));
    }

    // ── Cascade Delete ────────────────────────────────────────

    public function test_bulletin_deleted_when_game_deleted(): void
    {
        $bulletin = GameBulletin::factory()->create();
        $bulletinId = $bulletin->id;

        $bulletin->game->delete();

        $this->assertDatabaseMissing('game_bulletins', ['id' => $bulletinId]);
    }

    public function test_bulletin_deleted_when_user_deleted(): void
    {
        $bulletin = GameBulletin::factory()->create();
        $bulletinId = $bulletin->id;

        $bulletin->user->delete();

        $this->assertDatabaseMissing('game_bulletins', ['id' => $bulletinId]);
    }

    // ── Policy ────────────────────────────────────────────────

    public function test_policy_create_allows_game_owner(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($owner->can('create', [GameBulletin::class, $game]));
    }

    public function test_policy_create_denies_non_owner(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();

        $this->assertFalse($user->can('create', [GameBulletin::class, $game]));
    }

    public function test_policy_view_allows_owner(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $bulletin = GameBulletin::factory()->create(['game_id' => $game->id]);

        $this->assertTrue($owner->can('view', $bulletin));
    }

    public function test_policy_view_allows_approved_participant(): void
    {
        $participant = User::factory()->create();
        $game = Game::factory()->create();
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'status' => 'approved',
        ]);
        $bulletin = GameBulletin::factory()->create(['game_id' => $game->id]);

        $this->assertTrue($participant->can('view', $bulletin));
    }

    public function test_policy_view_denies_non_participant(): void
    {
        $stranger = User::factory()->create();
        $bulletin = GameBulletin::factory()->create();

        $this->assertFalse($stranger->can('view', $bulletin));
    }

    public function test_policy_delete_allows_owner(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $bulletin = GameBulletin::factory()->create(['game_id' => $game->id]);

        $this->assertTrue($owner->can('delete', $bulletin));
    }

    public function test_policy_delete_denies_non_owner(): void
    {
        $user = User::factory()->create();
        $bulletin = GameBulletin::factory()->create();

        $this->assertFalse($user->can('delete', $bulletin));
    }
}
