<?php

namespace Tests\Feature\Models;

use App\Models\GameBulletin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GameBulletinModelTest extends TestCase
{
    use DatabaseTransactions;

    // Validation note (M077/S01): factory-create + uuid primitives and the
    // is_expired null/future accessor dups were cut — the notExpired scope trio
    // plus GameBulletinBoardTest ('shows non-expired'/'excludes expired') cover
    // the same comparisons end-to-end. The past-expiry accessor pin stays
    // (asserted by OneShotEpilogueTest despite no app-code callers).

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

    public function test_is_expired_returns_true_when_past_expiry(): void
    {
        $bulletin = GameBulletin::factory()->create(['expires_at' => now()->subHour()]);

        $this->assertTrue($bulletin->is_expired);
    }

    // ── Content Length ────────────────────────────────────────

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
}
