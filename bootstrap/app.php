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
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Spatie\CookieConsent\CookieConsentMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'paddle/webhook',
            // Resend mail webhook (M061): a stateless public surface called by
            // Resend, not a browser form POST. Authenticity is enforced by the
            // Svix signature verifier in ResendWebhookController
            // (RESEND_WEBHOOK_SECRET). Mirrors the Paddle webhook precedent.
            'webhooks/resend',
            // Discord HTTP Interactions endpoint (M057/S03): a stateless
            // public surface called by Discord, not a browser form POST.
            // CSRF protection is N/A — authenticity is enforced by the
            // VerifyDiscordInteractionSignature middleware (Ed25519 over
            // timestamp + raw body). Mirrors the Paddle webhook precedent.
            'discord/interactions',
        ]);

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
        // VerifyDiscordInteractionSignature runs BEFORE the global stack
        // (StartSession, etc.) so a flood of forged interactions is rejected at
        // the cheapest possible point and never pays session-store I/O per
        // request — it is a no-op on every other route.
        $middleware->priority([
            VerifyDiscordInteractionSignature::class,
            SetLocale::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // SQLSTATE 22P02 — a client-supplied value cannot be represented as
        // the column type it was bound to. Postgres also raises 22P02 for
        // malformed casts to json/xml and similar, so the message is matched
        // too: only the "invalid input syntax for type uuid" class is treated
        // as client garbage from an id-bearing request. A non-locale-English
        // Postgres (lc_messages) simply fails the match and reports as
        // before — the safe direction (noise returns, nothing is hidden).
        // That is not an application error, so for HTTP requests it is
        // suppressed here and rendered below as the same 404 a well-formed
        // unknown id returns — otherwise each probe payload becomes a fresh
        // error-tracking issue and drowns out real findings. CLI (jobs,
        // commands) still reports: there the malformed value comes from
        // stored data, which is an application concern. Input funnels that
        // validate shape first (uuid_or_null()/uuid_array() helpers,
        // UuidBuilder find*()) keep legitimate traffic off this path — this
        // is the backstop, not the first line of defence.
        $malformedUuidIdentifier = fn (QueryException $e): bool => ($e->errorInfo[0] ?? null) === '22P02'
            && str_contains($e->getMessage(), 'invalid input syntax for type uuid');

        $exceptions->reportable(function (QueryException $e) use ($malformedUuidIdentifier) {
            // HTTP-only (the test runner reports as console, so unit tests
            // count as HTTP here to exercise this path).
            $isCli = app()->runningInConsole() && ! app()->runningUnitTests();

            if (! $isCli && $malformedUuidIdentifier($e)) {
                // Scrubbed like error tracking (PostHogExceptionReporter):
                // bearer-style path segments collapse to placeholders, and no
                // URL/query string or exception message is logged — both can
                // carry tokens or personal data the reporter would scrub.
                Log::warning('Malformed identifier rejected at the database boundary', [
                    'category' => 'malformed_identifier',
                    'path' => app(PostHogExceptionReporter::class)->scrubRequestPath(request()->path()),
                    'sqlstate' => '22P02',
                ]);

                // Stop the report chain: not an application error.
                return false;
            }
        });

        $exceptions->render(function (QueryException $e, Request $request) use ($malformedUuidIdentifier) {
            if (! $malformedUuidIdentifier($e)) {
                return null;
            }

            // Delegate to the framework's own NotFoundHttpException rendering
            // (not a hand-built response): the request then gets exactly what an
            // unknown route gets — html or json by content negotiation, and a
            // custom errors/404 view if one is ever added. No recursion risk:
            // nothing else registers a NotFoundHttpException renderable.
            return app(ExceptionHandler::class)->render($request, new NotFoundHttpException('Not Found'));
        });

        $exceptions->reportable(function (Throwable $e) {
            app(PostHogExceptionReporter::class)->report($e);
        });

        $exceptions->render(function (HttpException $e, $request) {
            if ($e->getStatusCode() === 403 && $request->is('admin*') && $request->acceptsHtml()) {
                return response()->view('errors.403', [], 403);
            }
        });
    })->create();
