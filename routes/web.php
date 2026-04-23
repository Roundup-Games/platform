<?php

use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaddleBillingController;
use App\Http\Controllers\PaddleWebhookController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

// ── API Endpoints (no locale prefix) ──────────────────

Route::post('api/geocode', [GeocodeController::class, 'geocode'])
    ->name('api.geocode');

// ── Root Redirect ──────────────────────────────────────
// Bare "/" detects preferred locale and redirects.

Route::get('/', function () {
    return redirect('/' . resolvePreferredLocale() . '/');
})->name('root');

// ── Paddle Webhook (no auth — called by Paddle) ──────

Route::post('paddle/webhook', PaddleWebhookController::class)
    ->name('cashier.webhook');

// ── OAuth ──────────────────────────────────────────────

Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])
    ->name('oauth.redirect');

Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('oauth.callback');

// ── Locale Switch ──────────────────────────────────────

Route::get('locale/switch/{locale}', [LocaleController::class, 'switch'])
    ->name('locale.switch');

// ── Sitemap ──────────────────────────────────────────

Route::get('/sitemap.xml', [SitemapController::class, 'index']);

// ── Locale-prefixed routes ─────────────────────────────

Route::prefix('{locale}')
    ->where(['locale' => implode('|', config('app.available_locales'))])
    ->middleware('set.locale')
    ->group(function () {

        // ── Public Pages ──────────────────────────────

        Route::get('/', [PageController::class, 'home'])->name('home');
        Route::get('/about', [PageController::class, 'about'])->name('about');
        Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('how-it-works');
        Route::get('/for-organizers', [PageController::class, 'forOrganizers'])->name('for-organizers');
        Route::get('/contact', [PageController::class, 'contact'])->name('contact');
        Route::get('/safety-tools', [PageController::class, 'safetyTools'])->name('safety-tools');
        Route::get('/game-systems', App\Livewire\GameSystems\GameSystemsPage::class)->name('game-systems');
        Route::get('/game-systems/{slug}', App\Livewire\GameSystems\GameSystemDetail::class)->name('game-systems.show')->where('slug', '[a-zA-Z0-9\-]+');
        Route::post('/contact', [PageController::class, 'submitContact'])
            ->middleware('throttle:5,1')
            ->name('contact.submit');

        // ── Authenticated (Breeze) ────────────────────

        Route::get('/dashboard', function () {
            return view('dashboard', [
                'gameCount' => \App\Models\Game::where('owner_id', auth()->id())->where('status', 'scheduled')->count(),
                'campaignCount' => \App\Models\Campaign::where('owner_id', auth()->id())->where('status', 'active')->count(),
            ]);
        })->middleware(['auth', 'verified', 'profile.complete'])->name('dashboard');

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            // Profile page (Livewire — handles all profile management inline)
            Route::get('/profile', App\Livewire\Profile\Show::class)->name('profile.show');

            // Keep profile.edit route name for backward compatibility (OAuth, Breeze redirects)
            Route::get('/profile/view', App\Livewire\Profile\Show::class)->name('profile.edit');

            // People page (following/followers/blocked)
            Route::get('/people', App\Livewire\People\PeoplePage::class)->name('people');

            // Notifications page (full paginated history)
            Route::get('/notifications', App\Livewire\Notifications\NotificationsPage::class)->name('notifications.index');
        });

        // ── Public Profile ────────────────────────────

        Route::get('/u/{user}', App\Livewire\Profile\PublicProfile::class)->name('profile.public');

        // ── Teams ─────────────────────────────────────

        Route::get('/teams', App\Livewire\Teams\BrowseTeams::class)->name('teams.browse');

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            Route::get('/teams/create', App\Livewire\Teams\CreateTeam::class)->name('teams.create');
            Route::get('/teams/invites', App\Livewire\Teams\PendingInvites::class)->name('teams.invites');
            Route::get('/teams/{slug}/manage', App\Livewire\Teams\ManageTeam::class)->name('teams.manage');
            Route::get('/teams/{slug}/roster', App\Livewire\Teams\ManageRoster::class)->name('teams.roster');
        });

        Route::get('/teams/{slug}', App\Livewire\Teams\TeamDetail::class)->name('teams.detail');

        // ── Events (Public) ──────────────────────────

        Route::get('/events', App\Livewire\Events\EventListing::class)->name('events.index');
        Route::get('/events/create', App\Livewire\Events\CreateEvent::class)
            ->name('events.create')
            ->middleware(['auth', 'verified', 'profile.complete']);
        Route::get('/events/{slug}', App\Livewire\Events\EventDetail::class)
            ->name('events.detail')
            ->where('slug', '[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*+');

        // ── Events (Authenticated) ───────────────────

        Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
            Route::get('/events/{slug}/manage', App\Livewire\Events\ManageEvent::class)->name('events.manage');
            Route::get('/events/{slug}/announcements', App\Livewire\Events\EventAnnouncements::class)->name('events.announcements');
            Route::get('/events/{slug}/register', App\Livewire\Events\RegisterForEvent::class)->name('events.register');
            Route::get('/events/{slug}/registrations', App\Livewire\Events\ManageRegistrations::class)->name('events.manage-registrations');
        });

        // ── Games ─────────────────────────────────────

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            Route::get('/games/create', App\Livewire\Games\CreateGame::class)->name('games.create');
            Route::get('/games/{id}/manage-participants', App\Livewire\Games\ManageParticipants::class)->name('games.manage-participants')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/games/{id}/apply', App\Livewire\Games\ApplyToGame::class)->name('games.apply')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        });

        Route::get('/discover', App\Livewire\Discovery\DiscoveryPortal::class)->name('discover');
        Route::get('/discover/board-games', App\Livewire\Discovery\BoardGamesDiscovery::class)->name('discover.board-games');
        Route::get('/discover/adventures', App\Livewire\Discovery\AdventuresDiscovery::class)->name('discover.adventures');
        Route::get('/near', fn () => redirect()->route('discover', app()->getLocale(), 301))->name('near');

        Route::get('/games', App\Livewire\Games\GamesPage::class)->name('games.index');
        Route::get('/games/{id}', App\Livewire\Games\GameDetail::class)->name('games.detail')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        // ── Campaigns ─────────────────────────────────

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            Route::get('/campaigns/create', App\Livewire\Campaigns\CreateCampaign::class)->name('campaigns.create');
            Route::get('/campaigns/{id}/apply', App\Livewire\Campaigns\ApplyToCampaign::class)->name('campaigns.apply')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/manage-participants', App\Livewire\Campaigns\ManageParticipants::class)->name('campaigns.manage-participants')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/add-session', App\Livewire\Campaigns\AddSessionToCampaign::class)->name('campaigns.add-session')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/reviews/write/{reviewable_type}/{reviewable_id}', App\Livewire\Reviews\WriteReview::class)->name('reviews.write');
            Route::get('/reviews/{reviewId}/report', App\Livewire\Reviews\ReportReview::class)->name('reviews.report');
        });

        Route::get('/campaigns', App\Livewire\Campaigns\CampaignsPage::class)->name('campaigns.index');
        Route::get('/campaigns/{id}', App\Livewire\Campaigns\CampaignDetail::class)->name('campaigns.detail')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        // ── Billing (authenticated) ───────────────────

        Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
            Route::get('/billing', App\Livewire\Billing\BillingPortal::class)->name('billing.portal');
            Route::get('/membership', App\Livewire\Billing\MembershipPage::class)->name('membership');
            Route::get('/billing/checkout/{planId?}', App\Livewire\Billing\Checkout::class)->name('billing.checkout');
            Route::post('/billing/one-time', [PaddleBillingController::class, 'oneTimeCheckout'])
                ->name('billing.one-time-checkout');
        });

        // ── Notification Unsubscribe (signed URL, no auth required) ──

        Route::get('/notifications/unsubscribe/{user}/{category}', [NotificationController::class, 'unsubscribe'])
            ->name('notifications.unsubscribe')
            ->middleware('signed');

        // ── Onboarding (authenticated, profile NOT complete) ──

        Route::middleware('auth')->group(function () {
            Route::get('/onboarding', App\Livewire\Onboarding\CompleteProfile::class)
                ->name('onboarding.index');
        });

        // ── Auth routes (Breeze) ──────────────────────
        require __DIR__.'/auth.php';

    });

// ── Locale-less URL fallback ─────────────────────────
// Redirects bare paths (e.g. /login, /discover, /about) to the
// locale-prefixed equivalent using Accept-Language + session.
// This must come after all explicit non-locale routes and the locale
// group so it only catches genuinely unmatched GET requests.

Route::get('/{path}', function (string $path) {
    // Skip paths that have their own handling outside the locale group
    if (preg_match('#^(admin|api|auth|filament|livewire|locale|paddle|storage|sitemap|telescope|up|vendor)/#i', $path)) {
        abort(404);
    }

    return redirect('/' . resolvePreferredLocale() . '/' . $path, 302);
})->where('path', '.*');
