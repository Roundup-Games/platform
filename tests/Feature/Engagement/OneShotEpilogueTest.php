<?php

use App\Enums\GameStatus;
use App\Jobs\AutoCompleteGames;
use App\Livewire\Games\GamesPage;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\OneShotEpilogueService;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

//
// One-shot Epilogue bulletin (M071 S03, D219).
//
// Completing a one-shot must auto-post the sticky, host-authored Epilogue
// bulletin with a ~30-day expiry, through the shared
// AttendanceService::handleGameCompletion seam both completion paths use.
// Campaign games are excluded (journal surface), the pinned flag survives
// the Game::updated completion/cancel expiry sweep, and creation is
// idempotent.
//

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    app()->setLocale('en');

    $this->owner = User::factory()->create(['profile_complete' => true]);
    $this->gameSystem = GameSystem::factory()->create();
});

function epilogueCreateScheduledOneShot(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Scheduled->value,
        'campaign_id' => null,
        ...$overrides,
    ]);
}

describe('host completion path (GamesPage::completeGame)', function () {
    it('creates exactly one pinned host-authored epilogue bulletin on one-shot completion', function () {
        $game = epilogueCreateScheduledOneShot($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)
            ->test(GamesPage::class)
            ->call('completeGame', $game->id);

        $bulletins = $game->bulletins()->get();

        expect($bulletins)->toHaveCount(1);

        $epilogue = $bulletins->first();
        expect($epilogue->is_pinned)->toBeTrue()
            ->and((string) $epilogue->user_id)->toBe((string) $this->owner->id)
            ->and($epilogue->content)->toBe(__('games.content_epilogue_prompt', [], 'en'))
            ->and($epilogue->expires_at)->not->toBeNull()
            ->and($epilogue->expires_at->between(now()->addDays(29), now()->addDays(31)))->toBeTrue();
    });

    it('creates no epilogue (and no bulletin) for a campaign game', function () {
        $campaign = Campaign::factory()->create();
        $game = epilogueCreateScheduledOneShot($this->owner, $this->gameSystem, [
            'campaign_id' => $campaign->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GamesPage::class)
            ->call('completeGame', $game->id);

        expect($game->fresh()->status)->toBe(GameStatus::Completed)
            ->and($game->bulletins()->count())->toBe(0);
    });
});

describe('auto-completion path (AutoCompleteGames)', function () {
    it('creates the epilogue bulletin when the job completes a one-shot', function () {
        Config::set('attendance.auto_complete_offset_hours', 0);

        $game = epilogueCreateScheduledOneShot($this->owner, $this->gameSystem, [
            'date_time' => now()->subHours(20),
            'expected_duration' => 3,
        ]);

        AutoCompleteGames::dispatchSync();

        expect($game->fresh()->status)->toBe(GameStatus::Completed);

        $epilogue = $game->bulletins()->where('is_pinned', true)->first();

        expect($epilogue)->not->toBeNull()
            ->and((string) $epilogue->user_id)->toBe((string) $this->owner->id)
            ->and($epilogue->content)->toBe(__('games.content_epilogue_prompt', [], 'en'))
            ->and($epilogue->expires_at->between(now()->addDays(29), now()->addDays(31)))->toBeTrue();
    });
});

describe('expiry sweep guard (Game::updated)', function () {
    it('keeps the pinned bulletin unexpired while expiring ordinary bulletins on cancel, and on re-transition to completed', function () {
        $game = epilogueCreateScheduledOneShot($this->owner, $this->gameSystem);

        $pinned = GameBulletin::factory()->pinned()->create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => now()->addDays(30),
        ]);
        $ordinary = GameBulletin::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $pinnedExpiry = $pinned->expires_at->toDateTimeString();

        // scheduled -> canceled
        $game->update(['status' => 'canceled']);

        expect($pinned->fresh()->expires_at->toDateTimeString())->toBe($pinnedExpiry)
            ->and($pinned->fresh()->is_expired)->toBeFalse()
            ->and($ordinary->fresh()->expires_at->isPast())->toBeTrue();

        // canceled -> completed re-transition runs the sweep again
        $game->update(['status' => 'completed']);

        expect($pinned->fresh()->expires_at->toDateTimeString())->toBe($pinnedExpiry)
            ->and($pinned->fresh()->is_expired)->toBeFalse();
    });
});

describe('idempotency (OneShotEpilogueService)', function () {
    it('creates only one pinned bulletin when invoked twice on a completed one-shot', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Completed->value,
            'campaign_id' => null,
        ]);

        $service = app(OneShotEpilogueService::class);

        $first = $service->createFor($game);
        $second = $service->createFor($game);

        expect($first)->toBeInstanceOf(GameBulletin::class)
            ->and($second)->toBeNull()
            ->and($game->bulletins()->where('is_pinned', true)->count())->toBe(1);
    });

    it('does not create an epilogue for a campaign game or a non-completed game', function () {
        $campaign = Campaign::factory()->create();
        $campaignGame = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Completed->value,
            'campaign_id' => $campaign->id,
        ]);
        $scheduledGame = epilogueCreateScheduledOneShot($this->owner, $this->gameSystem);

        $service = app(OneShotEpilogueService::class);

        expect($service->createFor($campaignGame))->toBeNull()
            ->and($service->createFor($scheduledGame))->toBeNull()
            ->and($campaignGame->bulletins()->count())->toBe(0)
            ->and($scheduledGame->bulletins()->count())->toBe(0);
    });
});

describe('failure isolation (epilogue is best-effort)', function () {
    it('completes the game even when the epilogue fan-out throws — no rollback, no escaped exception', function () {
        $game = epilogueCreateScheduledOneShot($this->owner, $this->gameSystem);

        // Simulate the fan-out exploding mid-bulletin-creation (observer
        // cache invalidation, Discord dispatch under a sync queue, …) —
        // after the completion transaction commits, per the afterCommit
        // deferral in handleGameCompletion.
        GameBulletin::creating(function (): void {
            throw new RuntimeException('epilogue fan-out exploded');
        });

        // The host path: status save + attendance window + epilogue, all
        // inside the caller's DB::transaction (completeGame wraps both).
        Livewire::actingAs($this->owner)
            ->test(GamesPage::class)
            ->call('completeGame', $game->id)
            ->assertHasNoErrors();

        $fresh = $game->fresh();

        expect($fresh->status)->toBe(GameStatus::Completed)
            ->and($fresh->attendance_window_opens_at)->not->toBeNull()
            ->and($fresh->attendance_window_closes_at)->not->toBeNull()
            ->and(GameBulletin::query()->count())->toBe(0);
    });
});

describe('prompt locale (the game decides, not the completing actor)', function () {
    it('posts the German prompt for a German-language one-shot completed under an English-UI host', function () {
        $game = epilogueCreateScheduledOneShot($this->owner, $this->gameSystem, ['language' => 'de']);

        app()->setLocale('en');

        Livewire::actingAs($this->owner)
            ->test(GamesPage::class)
            ->call('completeGame', $game->id);

        expect($game->bulletins()->first()?->content)
            ->toBe(__('games.content_epilogue_prompt', [], 'de'))
            ->and(App::getLocale())->toBe('en');
    });
});
