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
use App\Observers\SeoModelObserver;
use App\Services\PostHogClient;
use App\Services\PostHogFeatureFlag;
use App\Services\ReliabilityScoreService;
use App\Translation\MissingTranslationCollector;
use App\Translation\TrackingTranslator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\SEO\BreadcrumbBuilder;
use RalphJSmit\Laravel\SEO\Facades\SEOManager;
use RalphJSmit\Laravel\SEO\Support\AlternateTag;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Laravel\Paddle\Cashier;
use Minishlink\WebPush\WebPush;
use PostHog\Posthog;

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

        // Centralized PostHog SDK wrapper — single point for enabled checks and SDK delegation.
        $this->app->singleton(PostHogClient::class);

        // Feature flag evaluation — singleton so per-request static cache is shared.
        // Clear the static cache at request end so long-running processes
        // (Octane, queue workers) don't serve stale flag decisions.
        $this->app->singleton(PostHogFeatureFlag::class);
        $this->app->terminating(function () {
            try {
                app(PostHogFeatureFlag::class)->clearCache();
            } catch (\Throwable) {
                // Graceful — tests may mock the singleton without clearCache expectations
            }
        });

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
        // Feature flag Blade directives
        // Blade::if creates @featureFlag / @else / @endfeatureFlag automatically.
        // Closing tags @endfeatureFlag and @endfeatureFlagVariant are auto-generated.
        Blade::if('featureFlag', fn (string $key) => app(PostHogFeatureFlag::class)->isOn($key));

        Blade::if('featureFlagVariant', fn (string $key, string $variant) => app(PostHogFeatureFlag::class)->getVariant($key) === $variant);

        // PostHog PHP SDK — initialize when API key is configured
        if (config('posthog.enabled', true) && config('posthog.api_key')) {
            Posthog::init(
                config('posthog.api_key'),
                [
                    'host' => config('posthog.host', 'https://eu.i.posthog.com'),
                ],
            );
        }
        // SEO: inject locale alternates (en/de/x-default) and canonical URL on every page
        // Bind TagManager as a singleton so seo()->for($model) in components persists to {!! seo() !!} in layout
        $this->app->singleton(\RalphJSmit\Laravel\SEO\TagManager::class);
        SEOManager::SEODataTransformer(function (SEOData $SEOData): SEOData {
            // Canonical URL — derive from config to respect scheme/host behind proxies
            if ($SEOData->canonical_url === null) {
                $SEOData->canonical_url = URL::to(request()->path());
            }

            // Locale alternates for hreflang tags
            if ($SEOData->alternates === null) {
                $currentPath = request()->path();
                $segments = explode('/', $currentPath);
                // Strip the locale prefix from the path
                $pathWithoutLocale = implode('/', array_slice($segments, 1));

                $locales = config('app.available_locales', ['en']);
                $defaultLocale = $locales[0];

                $alternates = [];
                foreach ($locales as $locale) {
                    $alternates[] = new AlternateTag($locale, url("{$locale}/{$pathWithoutLocale}"));
                }
                $alternates[] = new AlternateTag('x-default', url("{$defaultLocale}/{$pathWithoutLocale}"));

                $SEOData->alternates = $alternates;
            }

            // BreadcrumbList JSON-LD — automatically injected on all pages
            $breadcrumbCollection = app(BreadcrumbBuilder::class)->buildSchemaCollection($SEOData->title);
            if ($SEOData->schema === null) {
                $SEOData->schema = $breadcrumbCollection;
            } else {
                // Merge breadcrumb markup into existing schema collection
                foreach ($breadcrumbCollection->markup as $class => $builders) {
                    $SEOData->schema->markup[$class] = $builders;
                }
            }

            return $SEOData;
        });

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

        // Dashboard cache invalidation observers
        Game::observe(\App\Observers\GameObserver::class);
        GameParticipant::observe(\App\Observers\GameParticipantObserver::class);
        UserRelationship::observe(\App\Observers\UserRelationshipObserver::class);

        // SEO sitemap cache invalidation observer
        $seoObserver = $this->app->make(SeoModelObserver::class);
        GameSystem::observe($seoObserver);
        Event::observe($seoObserver);
        Game::observe($seoObserver);
        Campaign::observe($seoObserver);
        Team::observe($seoObserver);
        \App\Models\User::observe($seoObserver);

        // Activity logging observers — resilient, never block primary actions
        $activityObserver = $this->app->make(ActivityLogObserver::class);
        Game::observe($activityObserver);
        Campaign::observe($activityObserver);
        GameParticipant::observe($activityObserver);
        Review::observe($activityObserver);
        UserRelationship::observe($activityObserver);
    }
}
