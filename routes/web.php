<?php

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\InviteOptoutController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaddleBillingController;
use App\Http\Controllers\PaddleWebhookController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ShortLinkController;
use App\Http\Controllers\SitemapController;
use App\Livewire\Billing\BillingPortal;
use App\Livewire\Billing\Checkout;
use App\Livewire\Billing\MembershipPage;
use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Livewire\Campaigns\ApplyToCampaign;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Campaigns\CampaignsPage;
use App\Livewire\Campaigns\CreateCampaign;
use App\Livewire\Campaigns\PublicCampaignDetail;
use App\Livewire\Dashboard;
use App\Livewire\Discovery\AdventuresDiscovery;
use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Livewire\Discovery\DiscoveryPortal;
use App\Livewire\Events\CreateEvent;
use App\Livewire\Events\EventAnnouncements;
use App\Livewire\Events\EventDetail;
use App\Livewire\Events\EventListing;
use App\Livewire\Events\ManageEvent;
use App\Livewire\Events\ManageRegistrations;
use App\Livewire\Events\RegisterForEvent;
use App\Livewire\Games\ApplyToGame;
use App\Livewire\Games\CreateGame;
use App\Livewire\Games\GameDetail;
use App\Livewire\Games\GamesPage;
use App\Livewire\Games\ManageParticipants;
use App\Livewire\Games\PublicGameDetail;
use App\Livewire\GameSystems\GameSystemDetail;
use App\Livewire\GameSystems\GameSystemsPage;
use App\Livewire\GameSystems\MyRequestsPage;
use App\Livewire\GameSystems\RequestGameSystemPage;
use App\Livewire\GM\GmDirectory;
use App\Livewire\GM\GmWorkspace;
use App\Livewire\GM\SessionZero\CreateSessionZero;
use App\Livewire\Notifications\NotificationsPage;
use App\Livewire\Onboarding\CompleteProfile;
use App\Livewire\People\PeoplePage;
use App\Livewire\Profile\AuthenticatedProfile;
use App\Livewire\Profile\PublicProfile;
use App\Livewire\Profile\Show;
use App\Livewire\Reviews\ReportReview;
use App\Livewire\Reviews\WriteReview;
use App\Livewire\SessionZero\ViewSessionZero;
use App\Livewire\Support\BillingSupport;
use App\Livewire\Support\ContactSupport;
use App\Livewire\Teams\BrowseTeams;
use App\Livewire\Teams\CreateTeam;
use App\Livewire\Teams\ManageRoster;
use App\Livewire\Teams\ManageTeam;
use App\Livewire\Teams\PendingInvites;
use App\Livewire\Teams\TeamDetail;
use App\Livewire\Venues\ProposeVenue;
use App\Livewire\Venues\VenueDetail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Root Redirect ──────────────────────────────────────
// Bare "/" detects preferred locale and redirects.

Route::get('/', function () {
    return redirect('/'.resolvePreferredLocale().'/');
})->name('root');

// ── Short Link Redirect (no auth, locale-agnostic) ───

Route::get('/link/{code}', [ShortLinkController::class, 'redirect'])
    ->name('short-link.redirect')
    ->middleware('throttle:short-link')
    ->where('code', '[a-zA-Z0-9\-]{7,36}');

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
Route::get('/sitemap-{type}.xml', [SitemapController::class, 'show'])
    ->where('type', 'static|game-systems|events|games|campaigns|teams|profiles|venues');

// ── Legacy API Redirects (backward compatibility) ────
// Old /api/* routes redirect to /api/v1/* equivalents.
// Registered here only for GET routes (permanentRedirect).
// POST/DELETE legacy redirects live in routes/api.php
// (outside CSRF-protected web middleware) so service workers
// can still reach them.

Route::permanentRedirect('/api/geocode', '/api/v1/geocode');
Route::permanentRedirect('/api/push/vapid-public-key', '/api/v1/push/vapid-public-key');

// ── Locale-prefixed routes ─────────────────────────────

Route::prefix('{locale}')
    ->where(['locale' => implode('|', config('app.available_locales'))])
    ->middleware('set.locale')
    ->group(function () {

        // ── Public Pages ──────────────────────────────

        Route::get('/', [PageController::class, 'home'])->name('home');

        // Session Zero public view (UUID-based, no auth required to view)
        Route::get('/session-zero/{uuid}', ViewSessionZero::class)
            ->name('session-zero.view')
            ->where('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        Route::get('/about', [PageController::class, 'about'])->name('about');
        Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('how-it-works');

        // ── Our Pledge ────────────────────────────────────
        Route::prefix('our-pledge')->group(function () {
            Route::get('/', [PageController::class, 'ourPledge'])->name('pledge');
            Route::get('/algorithms', [PageController::class, 'algorithms'])->name('pledge.algorithms');
            Route::get('/finances', fn () => redirect()->route('pledge', app()->getLocale()))->name('pledge.finances');
            Route::get('/roadmap', fn () => redirect()->route('pledge', app()->getLocale()))->name('pledge.roadmap');
            Route::get('/operations', fn () => redirect()->route('pledge', app()->getLocale()))->name('pledge.operations');
        });
        Route::get('/for-organizers', [PageController::class, 'forOrganizers'])->name('for-organizers');
        Route::get('/contact', [PageController::class, 'contact'])->name('contact');
        Route::get('/safety-tools', [PageController::class, 'safetyTools'])->name('safety-tools');
        Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
        Route::get('/terms', [PageController::class, 'terms'])->name('terms');
        Route::get('/impressum', [PageController::class, 'impressum'])->name('impressum');
        Route::get('/gms', GmDirectory::class)->name('gm.directory');
        Route::get('/game-systems', GameSystemsPage::class)->name('game-systems');
        Route::get('/game-systems/request', RequestGameSystemPage::class)
            ->middleware(['auth', 'profile.complete'])
            ->name('game-systems.request');
        Route::get('/propose-venue', ProposeVenue::class)
            ->middleware(['auth', 'profile.complete'])
            ->name('venues.propose');
        Route::get('/game-systems/requests/mine', MyRequestsPage::class)
            ->middleware(['auth', 'profile.complete'])
            ->name('game-systems.requests.mine');
        Route::get('/game-systems/{slug}', GameSystemDetail::class)->name('game-systems.show')->where('slug', '[a-zA-Z0-9\-]+');
        Route::post('/contact', [PageController::class, 'submitContact'])
            ->middleware('throttle:5,1')
            ->name('contact.submit');

        // ── Authenticated (Breeze) ────────────────────

        Route::get('/dashboard', Dashboard::class)
            ->middleware(['auth', 'verified', 'profile.complete'])
            ->name('dashboard');

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            // GM Workspace (auth + GM role + subscription checked in component mount)
            Route::get('/gm-workspace', GmWorkspace::class)->name('gm.workspace');

            // GM Session Zero Builder
            Route::get('/gm/session-zero/create', CreateSessionZero::class)->name('gm.session-zero.create');
            Route::get('/gm/session-zero/create/{game_id}', CreateSessionZero::class)->name('gm.session-zero.create-for-game');

            // Profile page (Livewire — handles profile info and game preferences)
            Route::get('/profile', Show::class)->name('profile.show');

            // Settings page (Livewire — handles privacy, notifications, linked accounts, password)
            Route::get('/settings', App\Livewire\Settings\Show::class)->name('settings.show');

            // Support tickets (authenticated)
            Route::get('/support/account', ContactSupport::class)->name('support.account');
            Route::get('/support/billing', BillingSupport::class)->name('support.billing');

            // Keep profile.edit route name for backward compatibility (OAuth, Breeze redirects)
            Route::get('/profile/view', Show::class)->name('profile.edit');

            // People page (following/followers/blocked)
            Route::get('/people', PeoplePage::class)->name('people');

            // Authenticated profile view (always app-layout)
            Route::get('/dashboard/u/{user}', AuthenticatedProfile::class)
                ->where('user', '[a-zA-Z0-9][a-zA-Z0-9._-]*')
                ->name('profile.show-authenticated');

            // Notifications page (full paginated history)
            Route::get('/notifications', NotificationsPage::class)->name('notifications.index');
        });

        // ── Public Profile ────────────────────────────

        // UUID fallback for backward compatibility — redirect to canonical slug URL
        Route::get('/u/{uuid}', function (Request $request) {
            $uuid = $request->route('uuid');
            $user = User::where('id', $uuid)->firstOrFail();

            return redirect()->route('profile.public', [
                'locale' => app()->getLocale(),
                'user' => $user,
            ], 301);
        })->where('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        // Slug-based profile URL (primary)
        Route::get('/u/{user}', PublicProfile::class)
            ->where('user', '[a-zA-Z0-9][a-zA-Z0-9._-]*')
            ->name('profile.public');

        // ── Teams ─────────────────────────────────────

        Route::get('/teams', BrowseTeams::class)->name('teams.browse');

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            Route::get('/teams/create', CreateTeam::class)->name('teams.create');
            Route::get('/teams/invites', PendingInvites::class)->name('teams.invites');
            Route::get('/teams/{slug}/manage', ManageTeam::class)->name('teams.manage');
            Route::get('/teams/{slug}/roster', ManageRoster::class)->name('teams.roster');
        });

        Route::get('/teams/{slug}', TeamDetail::class)->name('teams.detail');

        // ── Events (Public) ──────────────────────────

        Route::get('/events', EventListing::class)->name('events.index');
        Route::get('/events/create', CreateEvent::class)
            ->name('events.create')
            ->middleware(['auth', 'verified', 'profile.complete']);
        Route::get('/events/{slug}', EventDetail::class)
            ->name('events.detail')
            ->where('slug', '[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*+');

        // ── Events (Authenticated) ───────────────────

        Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
            Route::get('/events/{slug}/manage', ManageEvent::class)->name('events.manage');
            Route::get('/events/{slug}/announcements', EventAnnouncements::class)->name('events.announcements');
            Route::get('/events/{slug}/register', RegisterForEvent::class)->name('events.register');
            Route::get('/events/{slug}/registrations', ManageRegistrations::class)->name('events.manage-registrations');
        });

        // ── Games ─────────────────────────────────────

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            Route::get('/games/create', CreateGame::class)->name('games.create');
            Route::get('/dashboard/games/{id}', GameDetail::class)->name('games.show')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/games/{id}/manage-participants', ManageParticipants::class)->name('games.manage-participants')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/games/{id}/apply', ApplyToGame::class)->name('games.apply')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        });

        Route::get('/discover', DiscoveryPortal::class)->name('discover');
        Route::get('/discover/board-games', BoardGamesDiscovery::class)->name('discover.board-games');
        Route::get('/discover/adventures', AdventuresDiscovery::class)->name('discover.adventures');
        Route::get('/near', fn () => redirect()->route('discover', app()->getLocale(), 301))->name('near');

        Route::get('/games', GamesPage::class)->name('games.index');
        Route::get('/games/{id}', PublicGameDetail::class)->name('games.detail')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        // ── Campaigns ─────────────────────────────────

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            Route::get('/campaigns/create', CreateCampaign::class)->name('campaigns.create');
            Route::get('/dashboard/campaigns/{id}', CampaignDetail::class)->name('campaigns.show')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/apply', ApplyToCampaign::class)->name('campaigns.apply')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/manage-participants', App\Livewire\Campaigns\ManageParticipants::class)->name('campaigns.manage-participants')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/add-session', AddSessionToCampaign::class)->name('campaigns.add-session')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/reviews/write/{reviewable_type}/{reviewable_id}', WriteReview::class)->name('reviews.write');
            Route::get('/reviews/{reviewId}/report', ReportReview::class)->name('reviews.report');
        });

        Route::get('/campaigns', CampaignsPage::class)->name('campaigns.index');
        Route::get('/campaigns/{id}', PublicCampaignDetail::class)->name('campaigns.detail')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        // ── Venues ───────────────────────────────────

        // M053/S02: public venue page. Only verified commercial venues render —
        // VenueDetail::mount() aborts 404 for private/unverified/`other` locations
        // via LocationDisclosureService::isPublicVenuePage() (the single authority).
        Route::get('/venue/{slug}', VenueDetail::class)
            ->name('venues.detail')
            ->where('slug', '[a-zA-Z0-9\-]+');

        // ── Billing (authenticated) ───────────────────

        Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
            Route::get('/billing', BillingPortal::class)->name('billing.portal');
            Route::get('/membership', MembershipPage::class)->name('membership');
            Route::get('/billing/checkout/{planId?}', Checkout::class)->name('billing.checkout');
            Route::post('/billing/one-time', [PaddleBillingController::class, 'oneTimeCheckout'])
                ->name('billing.one-time-checkout');
        });

        // ── Invite Email Opt-out (public, rate-limited) ──
        // Two-step flow: GET shows confirmation page, POST performs suppression.
        // Prevents email scanners and link prefetchers from triggering false opt-outs.

        Route::get('/invite-optout/{emailHash}', [InviteOptoutController::class, 'show'])
            ->name('invite.optout.show')
            ->middleware('throttle:10,1')
            ->where('emailHash', '[a-f0-9]{64}');

        Route::post('/invite-optout/{emailHash}', [InviteOptoutController::class, 'confirm'])
            ->name('invite.optout.confirm')
            ->middleware('throttle:10,1')
            ->where('emailHash', '[a-f0-9]{64}');

        // ── Notification Unsubscribe (signed URL, no auth required) ──

        Route::get('/notifications/unsubscribe/{user}/{category}', [NotificationController::class, 'unsubscribe'])
            ->name('notifications.unsubscribe')
            ->middleware('signed');

        // ── Data Export Download (signed URL + auth) ──────
        // Signed URL expires after 7 days. Auth ensures only the data subject
        // can download their own export — prevents leakage via browser history
        // or URL forwarding.

        Route::get('/export/download/{user}', [ExportDownloadController::class, 'download'])
            ->name('export.download')
            ->middleware(['signed', 'auth']);

        // ── Onboarding (authenticated, profile NOT complete) ──

        Route::middleware('auth')->group(function () {
            Route::get('/onboarding', CompleteProfile::class)
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

    // If the path already starts with a valid locale prefix (e.g. "en/discovery"),
    // it was already locale-resolved and genuinely doesn't match any route — 404.
    // Without this check, the redirect adds another locale prefix on every request
    // causing an infinite redirect loop: /en/en/en/en/...
    $availableLocales = config('app.available_locales');
    $escapedLocales = implode('|', array_map('preg_quote', $availableLocales));
    if (preg_match('#^('.$escapedLocales.')/(.+)#i', $path, $matches)) {
        abort(404);
    }

    return redirect('/'.resolvePreferredLocale().'/'.$path, 302);
})->where('path', '.*');
