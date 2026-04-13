<?php

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\PaddleBillingController;
use App\Http\Controllers\PaddleWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// ── Public ─────────────────────────────────────────────

Route::get('/', function () {
    return view('welcome');
});

// ── Paddle Webhook (no auth — called by Paddle) ──────

Route::post('paddle/webhook', PaddleWebhookController::class)
    ->name('cashier.webhook');

// ── OAuth ──────────────────────────────────────────────

Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])
    ->name('oauth.redirect');

Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])
    ->name('oauth.callback');

// ── Authenticated (Breeze) ────────────────────────────

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified', 'profile.complete'])->name('dashboard');

Route::middleware(['auth', 'profile.complete'])->group(function () {
    // Livewire profile pages
    Route::get('/profile', App\Livewire\Profile\Show::class)->name('profile.show');
    Route::get('/profile/edit', App\Livewire\Profile\Edit::class)->name('profile.edit-form');

    // Keep profile.edit route name for backward compatibility (OAuth, Breeze redirects)
    Route::get('/profile/view', App\Livewire\Profile\Show::class)->name('profile.edit');

    // Breeze profile actions (update, delete)
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Teams ──────────────────────────────────────────────

Route::get('/teams', App\Livewire\Teams\BrowseTeams::class)->name('teams.browse');

Route::middleware(['auth', 'profile.complete'])->group(function () {
    Route::get('/teams/create', App\Livewire\Teams\CreateTeam::class)->name('teams.create');
    Route::get('/teams/invites', App\Livewire\Teams\PendingInvites::class)->name('teams.invites');
    Route::get('/teams/{slug}/manage', App\Livewire\Teams\ManageTeam::class)->name('teams.manage');
    Route::get('/teams/{slug}/roster', App\Livewire\Teams\ManageRoster::class)->name('teams.roster');
});

Route::get('/teams/{slug}', App\Livewire\Teams\TeamDetail::class)->name('teams.detail');

// ── Events (Public) ────────────────────────────────────

Route::get('/events', App\Livewire\Events\EventListing::class)->name('events.index');
Route::get('/events/create', App\Livewire\Events\CreateEvent::class)->name('events.create')->middleware(['auth', 'verified', 'profile.complete']);
Route::get('/events/{slug}', App\Livewire\Events\EventDetail::class)->name('events.detail')->where('slug', '[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*+');

// ── Events (Authenticated) ─────────────────────────────

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
    Route::get('/events/{slug}/manage', App\Livewire\Events\ManageEvent::class)->name('events.manage');
    Route::get('/events/{slug}/announcements', App\Livewire\Events\EventAnnouncements::class)->name('events.announcements');
    Route::get('/events/{slug}/register', App\Livewire\Events\RegisterForEvent::class)->name('events.register');
    Route::get('/events/{slug}/registrations', App\Livewire\Events\ManageRegistrations::class)->name('events.manage-registrations');
});

// ── Games ──────────────────────────────────────────────

Route::middleware(['auth', 'profile.complete'])->group(function () {
    Route::get('/games/create', App\Livewire\Games\CreateGame::class)->name('games.create');
    Route::get('/games/{id}/manage-participants', App\Livewire\Games\ManageParticipants::class)->name('games.manage-participants');
    Route::get('/games/{id}/apply', App\Livewire\Games\ApplyToGame::class)->name('games.apply');
});

Route::get('/games/{id}', App\Livewire\Games\GameDetail::class)->name('games.detail');

// ── Campaigns ──────────────────────────────────────────

Route::middleware(['auth', 'profile.complete'])->group(function () {
    Route::get('/campaigns/create', App\Livewire\Campaigns\CreateCampaign::class)->name('campaigns.create');
    Route::get('/campaigns/{id}/manage-participants', App\Livewire\Campaigns\ManageParticipants::class)->name('campaigns.manage-participants');
});

Route::get('/campaigns/{id}', App\Livewire\Campaigns\CampaignDetail::class)->name('campaigns.detail');

// ── Billing (authenticated) ────────────────────────────

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
    Route::get('/billing', App\Livewire\Billing\BillingPortal::class)->name('billing.portal');
    Route::get('/membership', App\Livewire\Billing\MembershipPage::class)->name('membership');
    Route::get('/billing/checkout/{planId?}', App\Livewire\Billing\Checkout::class)->name('billing.checkout');
    Route::post('/billing/one-time', [PaddleBillingController::class, 'oneTimeCheckout'])
        ->name('billing.one-time-checkout');
});

// ── Onboarding (authenticated, profile NOT complete) ──

Route::middleware('auth')->group(function () {
    Route::get('/onboarding', App\Livewire\Onboarding\CompleteProfile::class)
        ->name('onboarding.index');
});

require __DIR__.'/auth.php';
