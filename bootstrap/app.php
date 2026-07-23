<?php

use App\Http\Middleware\CachePublicPages;
use App\Http\Middleware\CaptureFirstTouch;
use App\Http\Middleware\EnsureLocaleDefaults;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureUserNotDisabled;
use App\Http\Middleware\PostHogIdentifyUsers;
use App\Http\Middleware\ProcessShareIntent;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackAppVisit;
use App\Http\Middleware\VerifyDiscordInteractionSignature;
use App\Services\PostHogExceptionReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\CookieConsent\CookieConsentMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'paddle/webhook',
            // Discord HTTP Interactions endpoint (M057/S03): a stateless
            // public surface called by Discord, not a browser form POST.
            // CSRF protection is N/A — authenticity is enforced by the
            // VerifyDiscordInteractionSignature middleware (Ed25519 over
            // timestamp + raw body). Mirrors the Paddle webhook precedent.
            'discord/interactions',
        ]);

        $middleware->append(EnsureUserNotDisabled::class);
        $middleware->append(EnsureLocaleDefaults::class);
        $middleware->append(CaptureFirstTouch::class);
        $middleware->append(CachePublicPages::class);
        $middleware->append(TrackAppVisit::class);
        $middleware->append(PostHogIdentifyUsers::class);
        $middleware->append(ProcessShareIntent::class);
        $middleware->append(CookieConsentMiddleware::class);

        $middleware->alias([
            'profile.complete' => EnsureProfileComplete::class,
            'not.disabled' => EnsureUserNotDisabled::class,
            'set.locale' => SetLocale::class,
            // Verifies the Discord Interactions Ed25519 signature. Route-scoped
            // to POST /discord/interactions (M057/S03) — the only public surface
            // that should accept Discord-signed requests.
            'discord.signature' => VerifyDiscordInteractionSignature::class,
        ]);

        // SetLocale must run before SubstituteBindings so that
        // URL::defaults() is set before route model binding occurs.
        $middleware->priority([
            SetLocale::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $e) {
            app(PostHogExceptionReporter::class)->report($e);
        });

        $exceptions->render(function (HttpException $e, $request) {
            if ($e->getStatusCode() === 403 && $request->is('admin*') && $request->acceptsHtml()) {
                return response()->view('errors.403', [], 403);
            }
        });
    })->create();
