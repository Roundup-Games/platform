<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\CreateGame;
use App\Livewire\Games\GamesPage;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Support\Livewire\RestoreLocaleUrlDefaults;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Livewire\Livewire;

// M084/S09-T01 — Livewire redirects keep the visitor's locale.
//
//   /de/ session, "Mit Änderungen neu anlegen"  →  game cancelled, browser lands on
//                                                   /en/games/create?recreate=…
//   /de/ session, publish                        →  lands on the /en/ game page
//
// {locale} is a REQUIRED route parameter, so every route() call fills it from
// URL::defaults(). A Livewire update request has no {locale} segment and meets
// no SetLocale; the global EnsureLocaleDefaults runs before StartSession, reads
// an empty session and pins the fallback. SupportLocales restores only the app
// locale from the snapshot memo, so the copy stays German while URLs turn
// English — RestoreLocaleUrlDefaults restores the URL default on hydrate too.

// ── Helpers (shared with the cases S09-T02 adds to this file) ──

/** What SetLocale leaves behind on a page load under that locale. */
function livewireLocaleTestSession(string $locale): void
{
    app()->setLocale($locale);
    URL::defaults(['locale' => $locale]);
}

/** The state a real Livewire update request is in once its middleware has run. */
function livewireLocaleTestUpdateRequest(): void
{
    // EnsureLocaleDefaults is global middleware and runs before StartSession: on a
    // real request its session read is empty and it pins the fallback locale.
    URL::defaults(['locale' => config('app.fallback_locale')]);

    // A fresh process has not restored the app locale yet; Livewire's
    // SupportLocales does that from the snapshot memo during hydrate.
    app()->setLocale(config('app.locale'));
}

/** A component with no locale logic of its own: every URL it emits comes from route(). */
function livewireLocaleTestComponent(): Component
{
    return new class extends Component
    {
        public function signIn(): void
        {
            $this->redirectRoute('login');
        }

        public function noop(): void {}

        public function render(): string
        {
            // ONE root element — Livewire rejects two.
            return '<nav><a href="'.route('login').'">sign in</a></nav>';
        }
    };
}

// ── The defect: URLs issued during an update request ──────────────────────
//
// URL paths are asserted literally: they are route structure, not copy (K004
// does not apply).

describe('Livewire requests keep the visitor\'s locale', function () {
    it('redirects to the visitor\'s locale, whichever component issues the redirect', function (string $locale) {
        livewireLocaleTestSession($locale);
        $component = Livewire::test(livewireLocaleTestComponent());

        livewireLocaleTestUpdateRequest();

        // Split in three so the update request's middleware state is applied
        // after the page load and before the update itself.
        $component->call('signIn')->assertRedirect('/'.$locale.'/login');
    })->with([
        'german' => ['de'],
        'english' => ['en'],
    ]);

    it('keeps the visitor\'s locale on the URLs an update re-renders', function () {
        livewireLocaleTestSession('de');
        $component = Livewire::test(livewireLocaleTestComponent())->assertSeeHtml('/de/login');

        livewireLocaleTestUpdateRequest();

        $component->call('noop')
            ->assertSeeHtml('/de/login')
            ->assertDontSeeHtml('/en/login');
    });
});

describe('RestoreLocaleUrlDefaults trusts only a locale the app offers', function () {
    it('restores an offered locale and leaves the default alone otherwise', function (array $memo, string $expected) {
        URL::defaults(['locale' => 'en']);

        (new RestoreLocaleUrlDefaults)->hydrate($memo);

        expect(URL::getDefaultParameters()['locale'])->toBe($expected);
    })->with([
        'offered locale' => [['locale' => 'de'], 'de'],
        'a locale the app does not offer' => [['locale' => 'fr'], 'en'],
        'a memo without a locale' => [[], 'en'],
        'a non-string locale' => [['locale' => ['de']], 'en'],
    ]);
});

// ── The reported flows — S05 Step 11 ──────────────────────────────────────
//
// The two redirects a real browser reported out of a German session. Each is
// mounted the way a page load under /de/ leaves it, put through the state a
// real update request is in, and then driven through its action — the same
// three steps as the mechanism cases above, on the components that ship.

/**
 * A scheduled game starting in twelve hours (K014: relative to now) with its
 * host on the roster — the fixture "Recreate with changes" needs.
 */
function livewireLocaleTestScheduledGame(User $host): Game
{
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'campaign_id' => null,
        'name' => ['en' => 'Doors at eight'],
        'status' => GameStatus::Scheduled->value,
        'date_time' => now()->addHours(12),
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $game->fresh();
}

describe('the reported flows — S05 Step 11', function () {
    it('redirects "Recreate with changes" to the German create form, ?recreate= intact', function () {
        $host = gameTestCreateUserWithPermission();
        $game = livewireLocaleTestScheduledGame($host);
        livewireLocaleTestSession('de');
        $component = Livewire::actingAs($host)->test(GamesPage::class);
        livewireLocaleTestUpdateRequest();
        $component->call('recreateGame', $game->id)->assertRedirect('/de/games/create?recreate='.$game->id);
        expect($game->fresh()->status)->toBe(GameStatus::Canceled);
    });

    it('redirects publishing to the German detail page of the new game', function () {
        $host = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Catan']]);
        livewireLocaleTestSession('de');
        $component = Livewire::actingAs($host)->test(CreateGame::class);
        livewireLocaleTestUpdateRequest();
        $component->call('selectType', 'board_game')
            ->set('name', 'Board Game Night')
            ->set('game_system_id', $system->id)
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->call('save')
            ->assertRedirect('/de/dashboard/games/'.Game::query()->where('owner_id', $host->id)->sole()->id);
    });
});
