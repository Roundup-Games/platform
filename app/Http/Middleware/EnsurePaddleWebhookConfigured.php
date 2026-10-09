<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse Paddle webhook traffic when billing is unconfigured.
 *
 * Cashier registers its VerifyWebhookSignature middleware only when
 * cashier.webhook_secret resolves truthy — with the secret absent, a posted
 * payload would otherwise be processed WITHOUT any authenticity check. In
 * production that is never acceptable, so the route degrades instead: the
 * site boots and serves normally (a deployment may legitimately run without
 * Paddle), but /paddle/webhook answers 503 until PADDLE_WEBHOOK_SECRET is
 * set. Non-production environments keep the unsigned path so the test suite
 * and local webhook UAT work without a secret.
 */
class EnsurePaddleWebhookConfigured
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->app->isProduction() && ! filled(config('cashier.webhook_secret'))) {
            Log::warning('paddle.webhook_refused_unconfigured', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            abort(503, 'Paddle billing is not configured on this deployment.');
        }

        return $next($request);
    }
}
