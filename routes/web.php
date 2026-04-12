<?php

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// ── Public ─────────────────────────────────────────────

Route::get('/', function () {
    return view('welcome');
});

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

// ── Onboarding (authenticated, profile NOT complete) ──

Route::middleware('auth')->group(function () {
    Route::get('/onboarding', App\Livewire\Onboarding\CompleteProfile::class)
        ->name('onboarding.index');
});

require __DIR__.'/auth.php';
