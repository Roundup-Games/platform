<?php

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// M084/S05-T01 — linked recreate schema (D318/D319): the game→game link and
// the pending declaration marker.
//
//   recreates_game_id     on the NEW game  → the cancelled predecessor
//   recreate_declared_at  on the OLD game  → "Recreate with changes" declared
//
// The reliability flip that consumes them (AttendanceService::
// resolveLinkedRecreate) is covered by tests/Feature/Services/LinkedRecreateTest.php.

/**
 * A cancelled predecessor with the recreate declared, exactly as the cancel
 * flow leaves it (T03 stamps the marker before the offence is recorded).
 */
function declaredPredecessor(): Game
{
    return Game::factory()->create([
        'status' => GameStatus::Canceled->value,
        'recreate_declared_at' => now()->subMinutes(5),
    ]);
}

// ── Columns / cast / fillable ─────────────────────────────

test('a game persists recreates_game_id and recreate_declared_at', function () {
    $declaredAt = now()->subMinutes(5);
    $predecessor = Game::factory()->create(['recreate_declared_at' => $declaredAt]);

    $replacement = Game::factory()->create(['recreates_game_id' => $predecessor->id]);

    $predecessor->refresh();
    $replacement->refresh();

    // The marker round-trips through the timestamp column via its datetime cast.
    expect($predecessor->recreate_declared_at)->toBeInstanceOf(Carbon::class);
    $this->assertEqualsWithDelta(
        $declaredAt->getTimestamp(),
        $predecessor->recreate_declared_at->getTimestamp(),
        1,
        'The persisted declaration should match the assigned value.',
    );

    expect($replacement->recreates_game_id)->toBe($predecessor->id);

    $this->assertDatabaseHas('games', [
        'id' => $predecessor->id,
        'recreate_declared_at' => $predecessor->recreate_declared_at->toDateTimeString(),
    ]);
    $this->assertDatabaseHas('games', [
        'id' => $replacement->id,
        'recreates_game_id' => $predecessor->id,
    ]);
});

test('a fresh game has both recreate link columns null', function () {
    $game = Game::factory()->create();

    $game->refresh();

    expect($game->recreates_game_id)->toBeNull()
        ->and($game->recreate_declared_at)->toBeNull()
        ->and($game->recreates)->toBeNull()
        ->and($game->recreatedBy)->toBeNull();

    $this->assertDatabaseHas('games', [
        'id' => $game->id,
        'recreates_game_id' => null,
        'recreate_declared_at' => null,
    ]);
});

// ── Relations ────────────────────────────────────────────

test('recreates resolves the predecessor and recreatedBy the inverse', function () {
    $predecessor = declaredPredecessor();
    $replacement = Game::factory()->create(['recreates_game_id' => $predecessor->id]);

    $resolvedPredecessor = $replacement->recreates()->first();

    expect($resolvedPredecessor)->not->toBeNull()
        ->and($resolvedPredecessor->is($predecessor))->toBeTrue();

    $resolvedReplacement = $predecessor->recreatedBy()->first();

    expect($resolvedReplacement)->not->toBeNull()
        ->and($resolvedReplacement->is($replacement))->toBeTrue();
});

test('recreatedBy stays null for a cancelled game without a published replacement', function () {
    // The "declared but never published" state (D319): the offence stands.
    $predecessor = declaredPredecessor();

    expect($predecessor->recreatedBy()->exists())->toBeFalse();
});

// ── Foreign key behaviour ────────────────────────────────

test('deleting the predecessor nulls recreates_game_id instead of failing', function () {
    $predecessor = declaredPredecessor();
    $replacement = Game::factory()->create(['recreates_game_id' => $predecessor->id]);

    $predecessor->delete();

    // The replacement survives, detached.
    $this->assertDatabaseHas('games', ['id' => $replacement->id, 'recreates_game_id' => null]);
    expect($replacement->refresh()->recreates)->toBeNull();
});

test('recreates_game_id rejects a predecessor that does not exist', function () {
    $missingId = (string) Str::uuid();

    $caught = null;
    try {
        DB::transaction(fn () => Game::factory()->create(['recreates_game_id' => $missingId]));
        $this->fail('Expected a foreign key violation from games_recreates_game_id_foreign.');
    } catch (QueryException $e) {
        $caught = $e;
    }

    // Postgres: SQLSTATE 23503 naming the constraint.
    expect($caught->errorInfo[0] ?? null)->toBe('23503')
        ->and($caught->getMessage())->toContain('games_recreates_game_id_foreign');
});

test('recreates_game_id is indexed and deletes set null', function () {
    // K015: constrained() alone creates no index on the referencing column,
    // and Postgres never auto-indexes FKs. The index backs the "does this
    // cancelled game already have a replacement?" lookup on every publish.
    $indexNames = collect(Schema::getIndexes('games'))->pluck('name');

    expect($indexNames)->toContain('games_recreates_game_id_index');

    $foreignKey = collect(Schema::getForeignKeys('games'))
        ->first(fn (mixed $key): bool => data_get($key, 'name') === 'games_recreates_game_id_foreign');

    expect($foreignKey)->not->toBeNull()
        ->and(data_get($foreignKey, 'on_delete'))->toBe('set null');
});
