<?php

use App\Support\SystemHealth;
use Illuminate\Http\Response;

// Dependency-aware health (MEM1147): 200 'OK' when DB + Redis + cache
// answer; 503 with failing check names. The docker healthcheck gates on
// the exact 'OK' body, so outages surface as container health, not 500s.
Route::get('/up', function (): Response {
    $checks = SystemHealth::checks();

    return response(
        SystemHealth::healthy() ? 'OK' : json_encode($checks),
        SystemHealth::healthy() ? 200 : 503,
    );
});

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\DiscordBotInstallController;
use App\Http\Controllers\DiscordInteractionController;
use App\Http\Controllers\DiscordJoinController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\ICalFeedController;
use App\Http\Controllers\IndexNowKeyController;
use App\Http\Controllers\InviteOptoutController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaddleBillingController;
use App\Http\Controllers\PaddleWebhookController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ResendWebhookController;
use App\Http\Controllers\ShortLinkController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\EnsurePaddleWebhookConfigured;
use App\Livewire\Badges\BadgesGallery;
use App\Livewire\Billing\BillingPortal;
use App\Livewire\Billing\Checkout;
use App\Livewire\Billing\MembershipPage;
use App\Livewire\Billing\PublicPricing;
use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Livewire\Campaigns\ApplyToCampaign;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Campaigns\CampaignsPage;
use App\Livewire\Campaigns\CreateCampaign;
use App\Livewire\Campaigns\PublicCampaignDetail;
use App\Livewire\Charter\ViewCharter;
use App\Livewire\CityHubs\CityHubPage;
use App\Livewire\CityHubs\CityIndexPage;
use App\Livewire\Dashboard;
use App\Livewire\Discord\GuildSettings;
use App\Livewire\Discord\OrganizerGuilds;
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
use App\Livewire\GM\Charter\CharterAudit;
use App\Livewire\GM\Charter\CharterComposer;
use App\Livewire\GM\GmDirectory;
use App\Livewire\GM\GmWorkspace;
use App\Livewire\GM\SessionZero\CreateSessionZero;
use App\Livewire\Notifications\NotificationsPage;
use App\Livewire\Onboarding\CompleteProfile;
use App\Livewire\People\PeoplePage;
use App\Livewire\PlanSomething;
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
use App\Livewire\Venues\ClaimVenue;
use App\Livewire\Venues\ProposeVenue;
use App\Livewire\Venues\VenueDetail;
use App\Livewire\Venues\VenueDirectory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Root Redirect ──────────────────────────────────────
// Bare "/" detects preferred locale and redirects.

Route::get('/', function () {
    return redirect('/'.resolvePreferredLocale().'/');
})->name('root');

// ── IndexNow ownership key file ───────────────────────
// Engines fetch /{key}.txt to verify host ownership before accepting
// submissions. 404s unless the filename matches the configured key
// exactly. The 32-hex constraint cannot shadow other top-level routes.
Route::get('/{keyfile}', IndexNowKeyController::class)
    ->where('keyfile', '[a-f0-9]{32}\\.txt')
    ->name('indexnow.key');

// ── Short Link Redirect (no auth, locale-agnostic) ───

Route::get('/link/{code}', [ShortLinkController::class, 'redirect'])
    ->name('short-link.redirect')
    ->middleware('throttle:short-link')
    ->where('code', '[a-zA-Z0-9\-]{7,36}');

// ── iCal Feed (per-user, tokenized, locale-agnostic — D123) ──
// Lives OUTSIDE the {locale} group because calendar clients (Google/Apple)
// poll a raw URL with no locale prefix and no session/cookies. The token is
// a ShortLink code (linkable=User, purpose='ical'); the controller resolves
// locale from the user's preferred_language. Unknown/expired tokens → 404.
Route::get('/calendar/{code}', [ICalFeedController::class, 'show'])
    ->name('ical.feed')
    ->middleware('throttle:short-link')
    ->where('code', '[a-zA-Z0-9\-]{7,36}');

// ── Paddle Webhook (no auth — called by Paddle) ──────
// Authenticity is enforced by Cashier's VerifyWebhookSignature (registered
// when PADDLE_WEBHOOK_SECRET is set) — and when billing is NOT configured,
// EnsurePaddleWebhookConfigured refuses the traffic instead of letting an
// unverified payload through (the route degrades; the site does not).
Route::post('paddle/webhook', PaddleWebhookController::class)
    ->middleware(EnsurePaddleWebhookConfigured::class)
    ->name('cashier.webhook');

// ── Resend mail webhook (no auth — called by Resend) ───
// Ingests post-delivery events (delivered / bounced / complained / failed) so
// we can observe what Resend actually did with each message and suppress
// addresses that hard-bounce or complain. Authenticity is enforced by the
// Svix signature verifier inside the controller (RESEND_WEBHOOK_SECRET), so
// CSRF is N/A — mirrors the Paddle webhook precedent.
Route::post('webhooks/resend', ResendWebhookController::class)
    ->name('webhooks.resend');

// ── Discord HTTP Interactions endpoint (M057/S03) ─────
// Public, stateless surface called by Discord (NOT a browser form POST).
// CSRF-excluded in bootstrap/app.php (Paddle precedent). Authenticity is
// enforced by the discord.signature middleware: Ed25519 verification over
// (timestamp + raw body) — fail closed, no bypass. Rides the standard web
// middleware stack like the Paddle webhook; the global middleware gracefully
// no-ops on unauthenticated/session-less requests.
Route::post('discord/interactions', DiscordInteractionController::class)
    ->middleware('discord.signature')
    ->name('discord.interactions');

// ── OAuth ──────────────────────────────────────────────

Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])
    ->name('oauth.redirect');

Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('oauth.callback');

// ── Discord Bot Install (M057/T06) ────────────────────
// Top-level (outside the locale prefix) because Discord redirects back to a
// fixed callback URL — mirrors the login OAuth routes above. The landlord is
// already roundup-authenticated; this is the bot-add round-trip, not login.

Route::get('discord/install', [DiscordBotInstallController::class, 'redirect'])
    ->middleware(['auth', 'not.disabled', 'verified'])
    ->name('discord.install.redirect');

Route::get('discord/install/callback', [DiscordBotInstallController::class, 'callback'])
    ->middleware(['auth', 'not.disabled', 'verified', 'throttle:10,1'])
    ->name('discord.install.callback');

// ── Discord "My seat" on-ramp for unlinked members (M059/S02) ──
// Guest-accessible: the ephemeral "Link Discord to grab your seat" button
// deep-links here. Records the targeted game as a session intent and hands
// off to Discord OAuth; the callback + onboarding carry the intent through to
// the game join target.
Route::get('discord/join/{game}', DiscordJoinController::class)
    ->where('game', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
    ->middleware('throttle:10,1')
    ->name('discord.join');

// ── Locale Switch ──────────────────────────────────────

Route::get('locale/switch/{locale}', [LocaleController::class, 'switch'])
    ->name('locale.switch');

// ── Sitemap ──────────────────────────────────────────

Route::get('/sitemap.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-{type}.xml', [SitemapController::class, 'show'])
    // M066/S06/T02: 'teams' retired — teams pages sit behind login and are
    // no longer indexable. Constraint mirrors SeoCacheService::SITEMAP_TYPES.
    ->where('type', 'static|game-systems|events|games|campaigns|profiles|venues|cities');

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

        // Campaign charter public read-only view (M070) — same UUID
        // capability-link trust model as session-zero.view above.
        Route::get('/charter/{uuid}', ViewCharter::class)
            ->name('charter.view')
            ->where('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        Route::get('/about', [PageController::class, 'about'])->name('about');
        Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('how-it-works');

        // ── Our Pledge ────────────────────────────────────
        Route::prefix('our-pledge')->group(function () {
            Route::get('/', [PageController::class, 'ourPledge'])->name('pledge');
            Route::get('/algorithms', [PageController::class, 'algorithms'])->name('pledge.algorithms');
            // D179 honesty policy: the financial-transparency page was never
            // published — the alias answers 410 Gone instead of looping back
            // to the hub with an unfulfilled claim.
            Route::get('/finances', fn () => abort(410))->name('pledge.finances');
            Route::get('/roadmap', [PageController::class, 'roadAhead'])->name('pledge.roadmap');
            // Operations page ships with S08; until then the alias answers
            // 410 Gone — no Coming-Soon redirect loop back to the hub.
            Route::get('/operations', fn () => abort(410))->name('pledge.operations');
        });
        Route::get('/for-organizers', [PageController::class, 'forOrganizers'])->name('for-organizers');
        Route::get('/contact', [PageController::class, 'contact'])->name('contact');
        // M066/S08/T02: real FAQ page — absorbs the contact-page stub and
        // answers the terminology/fees/trust questions the audit surfaced.
        Route::get('/faq', [PageController::class, 'faq'])->name('faq');
        Route::get('/safety-tools', [PageController::class, 'safetyTools'])->name('safety-tools');
        // M066/S08/T03: Code of Conduct under /safety — community rules,
        // the moderation consequences ladder, and the report/appeal path.
        // Guest-visible anchor the moderation flows can point at.
        Route::get('/safety/code-of-conduct', [PageController::class, 'codeOfConduct'])->name('safety.code-of-conduct');
        // M066/S08/T01: public pricing page — guest-visible, reads the same
        // MembershipType::active() source as the authenticated /membership
        // page. Component routes guests to signup, members to /membership.
        Route::get('/pricing', PublicPricing::class)->name('pricing');
        Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
        Route::get('/terms', [PageController::class, 'terms'])->name('terms');
        Route::get('/impressum', [PageController::class, 'impressum'])->name('impressum');
        Route::get('/gms', GmDirectory::class)->name('gm.directory');
        // M069/S04: guest-visible badge gallery — the public discovery + SEO
        // surface for every active badge definition (category pills, rarity
        // display, dormant coming-soon cards). Static sitemap entry ships in
        // the same change (SitemapController::STATIC_PATHS), so the
        // routing-hygiene/sitemap count tests stay green.
        Route::get('/badges', BadgesGallery::class)->name('badges.gallery');
        Route::get('/game-systems', GameSystemsPage::class)->name('game-systems');
        Route::get('/game-systems/request', RequestGameSystemPage::class)
            ->middleware(['auth', 'not.disabled', 'profile.complete'])
            ->name('game-systems.request');
        Route::get('/propose-venue', ProposeVenue::class)
            ->middleware(['auth', 'not.disabled', 'profile.complete'])
            ->name('venues.propose');
        Route::get('/game-systems/requests/mine', MyRequestsPage::class)
            ->middleware(['auth', 'not.disabled', 'profile.complete'])
            ->name('game-systems.requests.mine');
        Route::get('/game-systems/{slug}', GameSystemDetail::class)->name('game-systems.show')->where('slug', '[a-zA-Z0-9\-]+');

        // ── City Hubs (M062) ────────────────────────────

        // Index page (M066/S06/T04): every qualifying hub in one crawlable
        // list — the entry rail on /discover and the footer link here. Must
        // stay registered above /cities/{slug} so the bare path resolves to
        // the index, never the hub route's optional-looking prefix match.
        Route::get('/cities', CityIndexPage::class)
            ->name('city-hubs.index');

        // Programmatic-SEO city landing page. CityHubPage::mount() 404s any
        // slug that does not resolve to a single qualifying city cluster via
        // CityDirectoryService (upcoming-activity OR verified-venue threshold)
        // — non-qualifying cities never render a soft empty page.
        Route::get('/cities/{slug}', CityHubPage::class)
            ->name('city-hubs.show')
            ->where('slug', '[a-zA-Z0-9\-]+');
        Route::post('/contact', [PageController::class, 'submitContact'])
            ->middleware('throttle:5,1')
            ->name('contact.submit');

        // ── Authenticated (Breeze) ────────────────────

        Route::get('/dashboard', Dashboard::class)
            ->middleware(['auth', 'not.disabled', 'verified', 'profile.complete'])
            ->name('dashboard');

        Route::middleware(['auth', 'not.disabled', 'profile.complete'])->group(function () {
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

            // ── Discord Bot Guild Settings (M057/T06) ───────────
            // Landlord channel picker + pause switch. Gated to the roundup
            // user who installed the bot (owner_user_id) inside the Livewire
            // component via abort(403). The {guild} param is the Discord guild
            // snowflake (numeric string).
            Route::get('/discord/guilds/{guild}', GuildSettings::class)
                ->where('guild', '[0-9]+')
                ->name('discord.guild.settings');

            // ── Discord Organizer Auto-Discovery (M057/T07, D119) ────
            // Surfaces roundup-enabled servers the organizer is a member of
            // with a per-guild publish-here prompt. Auth + profile.complete
            // at the group level; the GM-role gate lives in GmWorkspace (this
            // surface is reachable standalone but targets organizers).
            Route::get('/discord/organizer-guilds', OrganizerGuilds::class)
                ->name('discord.organizer.guilds');
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
        // M066/S06/T02: Teams are demoted behind login — guests redirect to
        // login; the whole surface (browse, detail, create, invites, manage,
        // roster) rides the standard member middleware stack. The teams
        // sitemap type is retired alongside the guest access.

        Route::middleware(['auth', 'not.disabled', 'profile.complete'])->group(function () {
            Route::get('/teams', BrowseTeams::class)->name('teams.browse');
            Route::get('/teams/create', CreateTeam::class)->name('teams.create');
            Route::get('/teams/invites', PendingInvites::class)->name('teams.invites');
            Route::get('/teams/{slug}/manage', ManageTeam::class)->name('teams.manage');
            Route::get('/teams/{slug}/roster', ManageRoster::class)->name('teams.roster');
            Route::get('/teams/{slug}', TeamDetail::class)->name('teams.detail');
        });

        // ── Events (Public) ──────────────────────────

        Route::get('/events', EventListing::class)->name('events.index');
        Route::get('/events/create', CreateEvent::class)
            ->name('events.create')
            ->middleware(['auth', 'not.disabled', 'verified', 'profile.complete']);
        Route::get('/events/{slug}', EventDetail::class)
            ->name('events.detail')
            ->where('slug', '[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*+');

        // ── Events (Authenticated) ───────────────────

        Route::middleware(['auth', 'not.disabled', 'verified', 'profile.complete'])->group(function () {
            Route::get('/events/{slug}/manage', ManageEvent::class)->name('events.manage');
            Route::get('/events/{slug}/announcements', EventAnnouncements::class)->name('events.announcements');
            Route::get('/events/{slug}/register', RegisterForEvent::class)->name('events.register');
            Route::get('/events/{slug}/registrations', ManageRegistrations::class)->name('events.manage-registrations');
        });

        // ── Games ─────────────────────────────────────

        // Unified "Plan something" entry point — asks one-time vs recurring,
        // then routes to games.create or campaigns.create with smart defaults.
        Route::middleware(['auth', 'not.disabled', 'profile.complete'])->group(function () {
            Route::get('/plan', PlanSomething::class)->name('plan.create');
        });

        Route::middleware(['auth', 'not.disabled', 'profile.complete'])->group(function () {
            Route::get('/games/create', CreateGame::class)->name('games.create');
            Route::get('/dashboard/games', GamesPage::class)->name('games.index');
            Route::get('/dashboard/games/{id}', GameDetail::class)->name('games.show')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/games/{id}/manage-participants', ManageParticipants::class)->name('games.manage-participants')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/games/{id}/apply', ApplyToGame::class)->name('games.apply')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        });

        Route::get('/discover', DiscoveryPortal::class)->name('discover');
        Route::get('/discover/board-games', BoardGamesDiscovery::class)->name('discover.board-games');
        Route::get('/discover/adventures', AdventuresDiscovery::class)->name('discover.adventures');
        Route::get('/near', fn () => redirect()->route('discover', app()->getLocale(), 301))->name('near');

        // M066/S06/T01: "My Games" board moved to /dashboard/games (next to its
        // detail route); old /games URLs 301 so indexed links keep working.
        Route::get('/games', fn () => redirect()->route('games.index', app()->getLocale(), 301));
        Route::get('/games/{id}', PublicGameDetail::class)->name('games.detail')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        // ── Campaigns ─────────────────────────────────

        Route::middleware(['auth', 'not.disabled', 'profile.complete'])->group(function () {
            Route::get('/campaigns/create', CreateCampaign::class)->name('campaigns.create');
            Route::get('/dashboard/campaigns', CampaignsPage::class)->name('campaigns.index');
            Route::get('/dashboard/campaigns/{id}', CampaignDetail::class)->name('campaigns.show')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/apply', ApplyToCampaign::class)->name('campaigns.apply')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/manage-participants', App\Livewire\Campaigns\ManageParticipants::class)->name('campaigns.manage-participants')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/campaigns/{id}/add-session', AddSessionToCampaign::class)->name('campaigns.add-session')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/dashboard/campaigns/{id}/charter', CharterComposer::class)->name('campaigns.charter')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/dashboard/campaigns/{id}/charter/audit', CharterAudit::class)->name('campaigns.charter.audit')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::get('/reviews/write/{reviewable_type}/{reviewable_id}', WriteReview::class)->name('reviews.write');
            Route::get('/reviews/{reviewId}/report', ReportReview::class)->name('reviews.report');
        });

        // M066/S06/T01: campaigns board moved to /dashboard/campaigns (next to its
        // detail route); old /campaigns URLs 301 so indexed links keep working.
        Route::get('/campaigns', fn () => redirect()->route('campaigns.index', app()->getLocale(), 301));
        Route::get('/campaigns/{id}', PublicCampaignDetail::class)->name('campaigns.detail')->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        // ── Venues ───────────────────────────────────

        // Public venue directory — the browse/index entry point for verified
        // commercial venues (the piece M053 deliberately left unbuilt). Linked
        // from the footer under the GM Directory. Sources exclusively from
        // Location::publicVenuePage() so the "who gets a public page" rule can
        // never drift from the venue-detail 404 gate and the sitemap.
        Route::get('/venues', VenueDirectory::class)->name('venues.directory');

        // M053/S02: public venue page. Only verified commercial venues render —
        // VenueDetail::mount() aborts 404 for private/unverified/`other` locations
        // via LocationDisclosureService::isPublicVenuePage() (the single authority).
        Route::get('/venue/{slug}', VenueDetail::class)
            ->name('venues.detail')
            ->where('slug', '[a-zA-Z0-9\-]+');

        // M053/S04/T04: claim-an-existing-venue form. Auth + profile.complete
        // gated. ClaimVenue::mount() re-runs the same isPublicVenuePage() 404
        // gate as VenueDetail so only public venue pages are claimable.
        Route::get('/venue/{slug}/claim', ClaimVenue::class)
            ->middleware(['auth', 'not.disabled', 'profile.complete'])
            ->name('venues.claim')
            ->where('slug', '[a-zA-Z0-9\-]+');

        // ── Billing (authenticated) ───────────────────

        Route::middleware(['auth', 'not.disabled', 'verified', 'profile.complete'])->group(function () {
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

        // One-click opt-out from the weekly digest email. The digest bypasses the
        // per-category channel system (it has no category), so it gets its own
        // signed route that flips weekly_digest_enabled instead.
        Route::get('/notifications/unsubscribe-digest/{user}', [NotificationController::class, 'unsubscribeDigest'])
            ->name('notifications.unsubscribe-digest')
            ->middleware('signed');

        // ── Data Export Download (signed URL + auth) ──────
        // Signed URL expires after 7 days. Auth ensures only the data subject
        // can download their own export — prevents leakage via browser history
        // or URL forwarding.

        Route::get('/export/download/{user}', [ExportDownloadController::class, 'download'])
            ->name('export.download')
            ->middleware(['signed', 'auth', 'not.disabled']);

        // ── Onboarding (authenticated, profile NOT complete) ──

        Route::middleware('auth', 'not.disabled')->group(function () {
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

Route::get('/{path}', function (string $path = '') {
    // $path is optional because Laravel strips a null path parameter before
    // invoking this catch-all (Route::parametersWithoutNulls). That happens
    // for requests whose decoded path collapses to an empty segment — e.g. a
    // URL-encoded slash (`/%2F`) — which would otherwise reach the closure
    // with zero arguments and throw an ArgumentCountError (PostHog 019f17c8).
    // The default routes those edge cases to the locale home instead of a 500.

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
