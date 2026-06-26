<?php

namespace App\Providers;

use App\Listeners\DropDemoDomainMail;
use App\Listeners\HandleGameSystemTicketClosed;
use App\Listeners\HandleGameSystemTicketResolved;
use App\Listeners\SuppressAutomatedTicketStatusNotifications;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\Channels\PushChannel;
use App\Observers\ActivityLogObserver;
use App\Observers\GameBulletinObserver;
use App\Observers\GameObserver;
use App\Observers\GameParticipantObserver;
use App\Observers\ReviewObserver;
use App\Observers\SeoModelObserver;
use App\Observers\UserRelationshipObserver;
use App\Policies\Escalated\CannedResponsePolicy;
use App\Policies\Escalated\EscalatedAdminPolicy;
use App\Policies\Escalated\TicketPolicy;
use App\Policies\GameBulletinPolicy;
use App\SEO\BreadcrumbBuilder;
use App\Services\AttendanceService;
use App\Services\BenchService;
use App\Services\EscalatedBladeRenderer;
use App\Services\PostHogClient;
use App\Services\PostHogFeatureFlag;
use App\Services\ReliabilityScoreService;
use App\Services\WaitlistService;
use App\Translation\MissingTranslationCollector;
use App\Translation\TrackingTranslator;
use Escalated\Laravel\Contracts\EscalatedUiRenderer;
use Escalated\Laravel\Events\TicketClosed;
use Escalated\Laravel\Events\TicketResolved;
use Escalated\Laravel\Models\ApiToken;
use Escalated\Laravel\Models\Article;
use Escalated\Laravel\Models\ArticleCategory;
use Escalated\Laravel\Models\AuditLog;
use Escalated\Laravel\Models\Automation;
use Escalated\Laravel\Models\BusinessSchedule;
use Escalated\Laravel\Models\CannedResponse;
use Escalated\Laravel\Models\CustomField;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\Macro;
use Escalated\Laravel\Models\Role;
use Escalated\Laravel\Models\Skill;
use Escalated\Laravel\Models\SlaPolicy;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Models\TicketStatus;
use Escalated\Laravel\Models\Webhook;
use Filament\Facades\Filament;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;
use Minishlink\WebPush\WebPush;
use PostHog\PostHog;
use RalphJSmit\Laravel\SEO\Facades\SEOManager;
use RalphJSmit\Laravel\SEO\Support\AlternateTag;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use RalphJSmit\Laravel\SEO\TagManager;
use Spatie\Translatable\Facades\Translatable;

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

        $this->app->singleton(WaitlistService::class);

        $this->app->singleton(BenchService::class);

        $this->app->singleton(AttendanceService::class);

        // Escalated customer portal: use Blade renderer instead of default Inertia.
        $this->app->singleton(EscalatedUiRenderer::class, EscalatedBladeRenderer::class);

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
        // Admin panel supplement stylesheet.
        // Filament's precompiled theme lacks the plain Tailwind utilities (h-12,
        // h-3.5, space-y-*, ...) used by the escalated-dev Livewire components, so
        // icons inside TicketConversation/SatisfactionRating inflated to fill their
        // containers. This supplement is built from resources/css/filament/admin.css
        // (which @source's the escalated vendor views) and loaded ALONGSIDE the
        // default Filament theme via a head render hook. It also carries the ticket
        // detail header/content layout overrides.
        Filament::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => '<link rel="stylesheet" href="'
                .Vite::asset('resources/css/filament/admin.css')
                .'">',
        );

        // Spatie translatable fallback — any available locale if the requested one is missing
        Translatable::fallback(
            fallbackLocale: 'en',
            fallbackAny: true,
        );

        // Escalated ticket event listeners for game system requests
        EventFacade::listen(TicketResolved::class, HandleGameSystemTicketResolved::class);
        EventFacade::listen(TicketClosed::class, HandleGameSystemTicketClosed::class);

        // Safety net: never deliver to synthetic/demo (RFC 2606) email domains.
        // Fires on every send path (queue, scheduler, web) so demo data created by
        // DemoSeedCommand cannot leak into a live mailer.
        EventFacade::listen(MessageSending::class, DropDemoDomainMail::class);

        // Suppress customer-facing "Status Updated" notifications when a ticket
        // status change was system-initiated (no human causer) — e.g. the nightly
        // escalated:close-resolved archival job and escalation-rule transitions.
        // Human-initiated changes still notify normally.
        EventFacade::listen(
            NotificationSending::class,
            SuppressAutomatedTicketStatusNotifications::class
        );

        // Escalated helpdesk authorization gates
        // escalated-admin: full Escalated admin (settings, roles, webhooks, etc.)
        Gate::define('escalated-admin', fn ($user) => $user->hasRole('Platform Admin'));
        // escalated-agent: ticket agent (manage tickets, canned responses, macros)
        Gate::define('escalated-agent', fn ($user) => $user->hasRole('Platform Admin') || $user->hasRole('Service Admin'));

        // Escalated model policies — override vendor defaults for RBAC.
        // Agent resources (tickets): escalated-agent gate (Platform Admin + Service Admin)
        Gate::policy(Ticket::class, TicketPolicy::class);

        // Game bulletin policy — not auto-discovered because create() takes Game, not GameBulletin
        Gate::policy(GameBulletin::class, GameBulletinPolicy::class);
        // All admin-only resources use the same EscalatedAdminPolicy
        // (vendor policies for Department/Tag/SlaPolicy/EscalationRule use Gate::allows()
        // which doesn't work with Gate::forUser() in test contexts)
        Gate::policy(Department::class, EscalatedAdminPolicy::class);
        Gate::policy(Tag::class, EscalatedAdminPolicy::class);
        Gate::policy(SlaPolicy::class, EscalatedAdminPolicy::class);
        Gate::policy(EscalationRule::class, EscalatedAdminPolicy::class);
        Gate::policy(CannedResponse::class, CannedResponsePolicy::class);
        // Resources with no vendor policy — use generic admin-only policy
        Gate::policy(Macro::class, EscalatedAdminPolicy::class);
        Gate::policy(ApiToken::class, EscalatedAdminPolicy::class);
        Gate::policy(Automation::class, EscalatedAdminPolicy::class);
        Gate::policy(Webhook::class, EscalatedAdminPolicy::class);
        Gate::policy(Role::class, EscalatedAdminPolicy::class);
        Gate::policy(TicketStatus::class, EscalatedAdminPolicy::class);
        Gate::policy(Skill::class, EscalatedAdminPolicy::class);
        Gate::policy(CustomField::class, EscalatedAdminPolicy::class);
        Gate::policy(BusinessSchedule::class, EscalatedAdminPolicy::class);
        Gate::policy(Article::class, EscalatedAdminPolicy::class);
        Gate::policy(ArticleCategory::class, EscalatedAdminPolicy::class);
        Gate::policy(AuditLog::class, EscalatedAdminPolicy::class);

        // Feature flag Blade directives
        // Blade::if creates @featureFlag / @else / @endfeatureFlag automatically.
        // Closing tags @endfeatureFlag and @endfeatureFlagVariant are auto-generated.
        Blade::if('featureFlag', fn (string $key) => app(PostHogFeatureFlag::class)->isOn($key));

        Blade::if('featureFlagVariant', fn (string $key, string $variant) => app(PostHogFeatureFlag::class)->getVariant($key) === $variant);

        // PostHog PHP SDK — initialize when API key is configured
        $apiKey = config('posthog.api_key');
        if (config('posthog.enabled', true) && is_string($apiKey)) {
            $host = config('posthog.host', 'https://eu.i.posthog.com');
            PostHog::init(
                $apiKey,
                ['host' => is_string($host) ? $host : 'https://eu.i.posthog.com'],
            );
        }
        // SEO: inject locale alternates (en/de/x-default) and canonical URL on every page
        // Bind TagManager as a singleton so seo()->for($model) in components persists to {!! seo() !!} in layout
        $this->app->singleton(TagManager::class);
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
                if (! is_array($locales)) {
                    $locales = ['en'];
                }
                $defaultLocale = is_string($locales[0] ?? null) ? $locales[0] : 'en';

                $alternates = [];
                foreach ($locales as $locale) {
                    if (is_string($locale)) {
                        $alternates[] = new AlternateTag($locale, url("{$locale}/{$pathWithoutLocale}"));
                    }
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
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Short link rate limiter — 30 requests/minute per IP to prevent abuse
        RateLimiter::for('short-link', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
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

        Review::observe(ReviewObserver::class);

        // Dashboard cache invalidation observers
        Game::observe(GameObserver::class);
        GameParticipant::observe(GameParticipantObserver::class);
        GameBulletin::observe(GameBulletinObserver::class);
        UserRelationship::observe(UserRelationshipObserver::class);

        // SEO sitemap cache invalidation observer
        $seoObserver = $this->app->make(SeoModelObserver::class);
        GameSystem::observe($seoObserver);
        Event::observe($seoObserver);
        Game::observe($seoObserver);
        Campaign::observe($seoObserver);
        Team::observe($seoObserver);
        User::observe($seoObserver);

        // Activity logging observers — resilient, never block primary actions
        $activityObserver = $this->app->make(ActivityLogObserver::class);
        Game::observe($activityObserver);
        Campaign::observe($activityObserver);
        GameParticipant::observe($activityObserver);
        Review::observe($activityObserver);
        UserRelationship::observe($activityObserver);
    }
}
