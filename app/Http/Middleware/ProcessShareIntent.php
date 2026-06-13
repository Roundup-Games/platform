<?php

namespace App\Http\Middleware;

use App\Dto\ShareIntentResult;
use App\Models\User;
use App\Services\ShareIntentService;
use App\Services\ShortLinkService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ProcessShareIntent middleware.
 *
 * Intercepts encrypted cookies after auth transitions (registration, email
 * verification, login, onboarding completion) to auto-create participants.
 *
 * Two cookie flows:
 *   1. short_link_intent — carries short_link_id only; entity derived from link
 *   2. share_intent — carries entity_type, entity_id, share_token
 *
 * If the user's profile is incomplete, defers processing (cookie persists).
 */
class ProcessShareIntent
{
    public function __construct(
        private readonly ShareIntentService $shareIntentService,
        private readonly ShortLinkService $shortLinkService,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only process for authenticated users on page-level GET requests
        if (! $user instanceof User || ! $this->shouldProcess($request)) {
            return $next($request);
        }

        // ── Short link intent processing ───────────────────────────────
        $shortLinkIntent = $request->cookie('short_link_intent');

        if ($shortLinkIntent && $user->profile_complete) {
            $result = $this->processShortLinkIntent($shortLinkIntent, $user);

            if ($result->shouldRedirect && $result->redirectRoute) {
                return redirect()->route($result->redirectRoute, [
                    'id' => $result->entityId,
                ])->withCookie(cookie()->forget('short_link_intent'));
            }

            // Invalid or already processed — clear cookie
            if ($result->shouldClearCookie) {
                $response = $next($request);
                $response->withCookie(cookie()->forget('short_link_intent'));

                return $response;
            }
        } elseif ($shortLinkIntent) {
            Log::debug('short_link_intent.deferred_profile_incomplete', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);
        }

        // ── Share token intent processing (existing flow) ──────────────
        $shareIntent = $request->cookie('share_intent');

        if (! $shareIntent) {
            return $next($request);
        }

        if (! $user->profile_complete) {
            Log::debug('share_intent.deferred_profile_incomplete', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        $payload = $this->shareIntentService->parsePayload($shareIntent);

        if ($payload === null) {
            Log::warning('share_intent.invalid_payload', [
                'user_id' => $user->id,
            ]);

            return $this->clearCookie($next($request));
        }

        $result = $this->shareIntentService->processShareIntent(
            $payload,
            $user,
        );

        if ($result->shouldRedirect && $result->redirectRoute) {
            Log::info('share_intent.redirecting', [
                'user_id' => $user->id,
                'entity_type' => $payload['entity_type'],
                'entity_id' => $payload['entity_id'],
                'route' => $result->redirectRoute,
            ]);

            return redirect()->route($result->redirectRoute, [
                'id' => $payload['entity_id'],
            ])->withCookie(cookie()->forget('share_intent'));
        }

        return $this->clearCookie($next($request));
    }

    // ── HTTP-layer helpers ──────────────────────────────────

    /**
     * Process the short_link_intent cookie using the domain service.
     */
    private function processShortLinkIntent(mixed $cookieValue, User $user): ShareIntentResult
    {
        $payload = $this->shareIntentService->parseShortLinkPayload($cookieValue);

        if ($payload === null) {
            Log::warning('short_link_intent.invalid_payload', ['user_id' => $user->id]);

            return new ShareIntentResult(false, null, shouldClearCookie: true);
        }

        $shortLinkId = $payload['short_link_id'] ?? null;
        $shortLink = $this->shortLinkService->resolveLinkById(is_int($shortLinkId) || is_string($shortLinkId) ? (int) $shortLinkId : 0);

        if ($shortLink === null) {
            Log::warning('short_link_intent.link_not_found', [
                'user_id' => $user->id, 'short_link_id' => $payload['short_link_id'],
            ]);

            return new ShareIntentResult(false, null, shouldClearCookie: true);
        }

        return $this->shareIntentService->processShortLinkIntent($shortLink, $user);
    }

    private function clearCookie(Response $response): Response
    {
        $response->headers->setCookie(cookie()->forget('share_intent'));

        return $response;
    }

    /**
     * Determine if this request should be checked for share intent processing.
     */
    private function shouldProcess(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->is('api/*')
            && ! $request->header('X-Livewire');
    }
}
