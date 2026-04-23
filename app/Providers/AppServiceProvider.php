<?php

namespace App\Providers;

use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'event' => Event::class,
            'event_announcement' => EventAnnouncement::class,
            'game' => Game::class,
            'campaign' => Campaign::class,
            'team' => Team::class,
            'game_system' => GameSystem::class,
        ]);

        Review::observe(\App\Observers\ReviewObserver::class);
    }
}
