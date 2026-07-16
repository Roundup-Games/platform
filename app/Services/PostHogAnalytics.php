<?php

namespace App\Services;

use App\Contracts\Participant;
use App\Enums\AttendanceStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Consent-gated direct capture for product/funnel analytics events.
 *
 * Distinct from PostHogEventBridge, which forwards community-activity events
 * (game.created, follow.received, …) that also belong in the in-app activity
 * feed. This service is for events that are analytics-only: signup, onboarding,
 * attendance outcomes, and discovery intent. Routing them through the activity
 * feed would pollute it with product events the user never opted into seeing.
 *
 * Every call is consent-gated via PostHogConsentChecker and never throws —
 * analytics can never break the primary application flow. This is the same
 * resilience contract as PostHogClient and PostHogEventBridge.
 *
 * Pseudonymization posture: PostHog receives only the opaque user ID as the
 * distinctId. Name and email are never sent (see config/posthog.md). Events are
 * therefore pseudonymized — PostHog cannot re-identify a person without the
 * application database.
 */
class PostHogAnalytics
{
    public function __construct(
        private readonly PostHogClient $posthog,
        private readonly PostHogConsentChecker $consentChecker,
    ) {}

    /**
     * Capture a product analytics event for a user.
     *
     * No-op when PostHog is disabled or analytics consent is absent.
     * Never throws.
     *
     * @param  array<string, mixed>  $properties
     */
    public function capture(User $user, string $event, array $properties = []): void
    {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        if (! $this->hasConsent($user)) {
            return;
        }

        try {
            $this->posthog->capture([
                'distinctId' => (string) $user->id,
                'event' => $event,
                'properties' => $properties,
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.analytics.capture_failed', [
                'event' => $event,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Capture a resolved attendance outcome for reliability analytics.
     *
     * The distinctId is the reported participant's user — so the event lands on
     * their person timeline and powers reliability cohorts (no-show rate by game
     * system, by modality, by lead time). The reporter is carried as a property.
     *
     * Loads the participant's game and game systems to enrich the event; loads
     * are best-effort and logged on failure.
     *
     * @param  GameParticipant  $participant  The participant whose attendance was resolved.
     * @param  AttendanceStatus  $status  The resolved attendance outcome.
     * @param  string  $context  How it was resolved: report|consensus|admin_override|host_cancel.
     */
    public function captureAttendanceOutcome(
        GameParticipant $participant,
        AttendanceStatus $status,
        string $context,
    ): void {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        $userId = $participant->user_id;
        if ($userId === null) {
            // Invitee-by-email participants have no user account yet — nothing to attribute.
            return;
        }

        $user = $participant->user ?? User::find($userId);
        if ($user && ! $this->hasConsent($user)) {
            return;
        }

        try {
            $participant->loadMissing(['game.gameSystems']);

            $game = $participant->game;
            $representative = $game?->gameSystems?->first();
            $locationType = $game?->location['type'] ?? null;
            $scheduledAt = $game?->date_time;

            $this->posthog->capture([
                'distinctId' => (string) $userId,
                'event' => 'attendance.recorded',
                'properties' => [
                    'attendance_status' => $status->value,
                    'resolution_context' => $context,
                    'game_id' => $game?->id,
                    'game_system' => $representative?->name,
                    'game_system_id' => $representative?->id,
                    'location_type' => $locationType,
                    'is_online' => $locationType === 'online',
                    // Negative = resolved after the session; positive = resolved before (host late-cancel).
                    'hours_to_session' => $scheduledAt ? (int) round(now()->diffInHours($scheduledAt, false)) : null,
                    'participant_role' => $participant->role?->value,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.analytics.attendance_capture_failed', [
                'participant_id' => $participant->id,
                'user_id' => $userId,
                'status' => $status->value,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Capture first-touch acquisition attribution as permanent person properties.
     *
     * Surfaces the SEO/acquisition signal as a queryable person attribute
     * ($set_once) rather than requiring funnel reconstruction. Captures the
     * referer domain and entry path from the signup request. Uses $set_once so
     * only the first signup is recorded, even across multiple signup attempts.
     *
     * Privacy: referer is reduced to hostname only (full URLs may carry UTM/PII
     * in query strings). Analytics-tier — gated by consent like all capture.
     *
     * @param  string|null  $referer  Raw Referer header from the signup request.
     * @param  string|null  $entryPath  The request path at signup (e.g. the page they registered from).
     */
    public function identifyFirstTouch(User $user, ?string $referer, ?string $entryPath): void
    {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        if (! $this->hasConsent($user)) {
            return;
        }

        $refererDomain = $referer !== null && $referer !== ''
            ? (parse_url($referer, PHP_URL_HOST) ?: null)
            : null;

        // SEO conversion: detect which public content page drove the signup.
        // Laravel's auth middleware stores the protected URL a guest was
        // redirected from in session('url.intended'). Parse its path for known
        // public-route patterns (game/campaign/venue detail or apply).
        $intendedPath = $this->extractIntendedPath();
        $contentContext = $this->detectContentContext($intendedPath ?? $entryPath);

        try {
            $this->posthog->identify([
                'distinctId' => (string) $user->id,
                'properties' => [
                    '$set_once' => array_filter([
                        'first_touch_referer_domain' => $refererDomain,
                        'first_touch_entry_path' => $entryPath !== '' ? $entryPath : null,
                        'signup_content_type' => $contentContext['type'] ?? null,
                        'signup_content_slug' => $contentContext['slug'] ?? null,
                    ]),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.analytics.first_touch_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract the path from session('url.intended'), if set.
     */
    private function extractIntendedPath(): ?string
    {
        try {
            $intended = session('url.intended');
            if (! is_string($intended) || $intended === '') {
                return null;
            }

            $path = parse_url($intended, PHP_URL_PATH);

            return is_string($path) && $path !== '' ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Detect the public content type and slug from a URL path.
     *
     * Matches known public-route patterns:
     *   /{locale}/games/{slug} or /{locale}/games/apply/{slug}
     *   /{locale}/campaigns/{slug} or /{locale}/campaigns/apply/{slug}
     *   /{locale}/venues/{slug}
     *
     * @return array{type: ?string, slug: ?string}
     */
    private function detectContentContext(?string $path): array
    {
        if ($path === null || $path === '') {
            return ['type' => null, 'slug' => null];
        }

        try {
            // Strip optional locale prefix: /en/... or en/... (path() omits leading /)
            $stripped = preg_replace('#^/?[a-z]{2}/#', '', $path);

            if (preg_match('#^games/(?:apply/)?([^/]+)#', (string) $stripped, $m)) {
                return ['type' => 'game', 'slug' => $m[1]];
            }
            if (preg_match('#^campaigns/(?:apply/)?([^/]+)#', (string) $stripped, $m)) {
                return ['type' => 'campaign', 'slug' => $m[1]];
            }
            if (preg_match('#^venues/([^/]+)#', (string) $stripped, $m)) {
                return ['type' => 'venue', 'slug' => $m[1]];
            }
        } catch (\Throwable) {
            // Fall through to null
        }

        return ['type' => null, 'slug' => null];
    }

    /**
     * Resolve consent for a user across request and webhook/queue contexts.
     *
     * In a normal browser request, the cookie_consent cookie is the live signal
     * (checked via PostHogConsentChecker). In webhook/queue contexts (Paddle
     * server-to-server webhooks, queued jobs) there is no cookie — so we fall
     * back to the persisted analytics_consent column, which the identify
     * middleware keeps in sync on every authenticated GET. This is exactly the
     * use case the persisted column was added for.
     */
    private function hasConsent(User $user): bool
    {
        if ($this->consentChecker->hasAnalyticsConsent()) {
            return true;
        }

        return (bool) $user->analytics_consent;
    }

    /**
     * Capture a participant lifecycle transition for matching-quality analytics.
     *
     * Completes the application funnel: submitted → approved/rejected/promoted/
     * removed. The event lands on the affected participant's person timeline so
     * acceptance rates, fill velocity, and removal patterns are queryable per
     * cohort (by game system, modality, etc.).
     *
     * No-ops when the participant has no user account (invitee-by-email) or
     * consent is absent. Never throws.
     *
     * @param  Participant  $participant  The participant whose lifecycle changed.
     * @param  Game|Campaign|null  $entity  The game or campaign.
     * @param  string  $event  The transition event name (e.g. 'application.approved').
     * @param  array<string, mixed>  $extra  Additional properties.
     */
    public function captureParticipantTransition(
        Participant $participant,
        Game|Campaign|null $entity,
        string $event,
        array $extra = [],
    ): void {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        $userId = $participant->getUserId();
        if ($userId === null) {
            return;
        }

        $user = $participant->getUser() ?? User::find($userId);
        if ($user === null) {
            return;
        }

        if (! $this->hasConsent($user)) {
            return;
        }

        try {
            // Force-reload (not loadMissing) because the relationship may have
            // been accessed — and cached as empty — during entity creation.
            $entity?->load('gameSystems');
            $representative = $entity?->gameSystems?->first();

            $this->posthog->capture([
                'distinctId' => (string) $userId,
                'event' => $event,
                'properties' => array_merge([
                    'entity_type' => $entity instanceof Campaign ? 'campaign' : ($entity instanceof Game ? 'game' : null),
                    'entity_id' => $entity?->id,
                    'game_system' => $representative?->name,
                    'visibility' => $entity?->visibility?->value,
                    'is_online' => ($entity instanceof Game ? ($entity->location['type'] ?? null) : null) === 'online',
                ], $extra),
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.analytics.participant_transition_failed', [
                'event' => $event,
                'participant_id' => $participant->getId(),
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
