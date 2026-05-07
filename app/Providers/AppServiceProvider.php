<?php

namespace App\Providers;

use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\Team;
use App\Models\UserRelationship;
use App\Notifications\Channels\PushChannel;
use App\Observers\ActivityLogObserver;
use App\Services\ReliabilityScoreService;
use App\Translation\MissingTranslationCollector;
use App\Translation\TrackingTranslator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;
use Minishlink\WebPush\WebPush;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();

        // Web Push client singleton — configured from VAPID env vars.
        // Returns null when keys are missing so push code paths degrade
        // gracefully instead of crashing with an ErrorException.
        $this->app->singleton(WebPush::class, function ($app) {
            $publicKey = config('services.vapid.public_key');
            $privateKey = config('services.vapid.private_key');

            if (! $publicKey || ! $privateKey) {
                Log::warning('VAPID keys not configured, push notifications disabled');

                return null;
            }

            return new WebPush([
                'VAPID' => [
                    'subject' => config('services.vapid.subject'),
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);
        });

        // Missing translation tracking — only in local env
        $this->app->singleton(MissingTranslationCollector::class);

        $this->app->singleton(ReliabilityScoreService::class);

        $this->app->singleton(\App\Services\WaitlistService::class);

        $this->app->singleton(\App\Services\BenchService::class);

        $this->app->singleton(\App\Services\AttendanceService::class);

        $this->app->extend('translator', function ($translator, $app) {
            if (! $app->environment('local')) {
                return $translator;
            }

            $tracking = new TrackingTranslator(
                $translator->getLoader(),
                $translator->getLocale(),
            );
            $tracking->setFallback($translator->getFallback());

            return $tracking;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API rate limiter — used by throttle:api middleware on routes/api.php
        RateLimiter::for('api', function (Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Register custom notification channels
        Notification::extend('push', function ($app) {
            return $app->make(PushChannel::class);
        });

        // Persist missing translation log on request termination
        if ($this->app->environment('local')) {
            $this->app->terminating(function () {
                $this->app->make(MissingTranslationCollector::class)->persist();
            });
        }

        Relation::morphMap([
            'event' => Event::class,
            'event_announcement' => EventAnnouncement::class,
            'game' => Game::class,
            'campaign' => Campaign::class,
            'team' => Team::class,
            'game_system' => GameSystem::class,
        ]);

        Review::observe(\App\Observers\ReviewObserver::class);

        // Activity logging observers — resilient, never block primary actions
        $activityObserver = $this->app->make(ActivityLogObserver::class);
        Game::observe($activityObserver);
        Campaign::observe($activityObserver);
        GameParticipant::observe($activityObserver);
        Review::observe($activityObserver);
        UserRelationship::observe($activityObserver);
    }
}
