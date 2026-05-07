<?php

namespace App\Http\Middleware;

use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ProcessShareIntent middleware.
 *
 * Intercepts the 'share_intent' encrypted cookie after auth transitions
 * (registration, email verification, login, onboarding completion).
 * If the user has a complete profile and a valid share intent cookie:
 *   1. Validates the entity exists and share_token still matches
 *   2. Creates the user as a participant (approved/waitlisted/benched)
 *   3. Clears the cookie and redirects to the entity detail page
 *
 * If the user's profile is incomplete, defers processing (cookie persists).
 */
class ProcessShareIntent
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only process for authenticated users on page-level GET requests
        if (! $user || ! $this->shouldProcess($request)) {
            return $next($request);
        }

        $shareIntent = $request->cookie('share_intent');

        if (! $shareIntent) {
            return $next($request);
        }

        // Defer processing if profile is not yet complete.
        // The cookie persists and will be processed on the next authenticated
        // GET request after onboarding finishes.
        if (! $user->profile_complete) {
            Log::debug('share_intent.deferred_profile_incomplete', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        // Parse the cookie payload
        $payload = $this->parsePayload($shareIntent);

        if ($payload === null) {
            Log::warning('share_intent.invalid_payload', [
                'user_id' => $user->id,
            ]);
            $response = $next($request);

            return $this->clearCookie($response);
        }

        // Process the share intent
        $result = $this->processShareIntent($payload, $user);

        $response = $next($request);

        // Always clear the cookie after processing attempt (success, failure, or skip)
        $response = $this->clearCookie($response);

        // If we created a participant, redirect to the entity detail page
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

        return $response;
    }

    /**
     * Process the share intent: validate entity, check capacity, create participant.
     */
    private function processShareIntent(array $payload, $user): ShareIntentResult
    {
        $entityType = $payload['entity_type'];
        $entityId = $payload['entity_id'];
        $shareToken = $payload['share_token'];

        return match ($entityType) {
            'game' => $this->processGameShareIntent($entityId, $shareToken, $user),
            'campaign' => $this->processCampaignShareIntent($entityId, $shareToken, $user),
            default => $this->failResult("Unknown entity type: {$entityType}", $user->id, $entityType, $entityId),
        };
    }

    /**
     * Process a game share intent.
     */
    private function processGameShareIntent(string $entityId, string $shareToken, $user): ShareIntentResult
    {
        $game = Game::find($entityId);

        if (! $game) {
            Log::warning('share_intent.entity_not_found', [
                'user_id' => $user->id,
                'entity_type' => 'game',
                'entity_id' => $entityId,
            ]);

            return new ShareIntentResult(false, null);
        }

        // Validate share token
        if (! $this->validateShareToken($game, $shareToken, 'game', $entityId, $user->id)) {
            return new ShareIntentResult(false, null);
        }

        // Check if user is already a participant
        $existing = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            Log::info('share_intent.already_participant', [
                'user_id' => $user->id,
                'entity_type' => 'game',
                'entity_id' => $entityId,
                'existing_status' => $existing->status->value,
            ]);

            // Still redirect to the entity so user sees it
            return new ShareIntentResult(true, 'games.detail');
        }

        // Determine status based on capacity
        $status = $this->determineStatus($game, 'game');

        // Create participant
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Invited->value,
            'status' => $status,
            'join_source' => JoinSource::ShareLink,
        ]);

        Log::info('share_intent.participant_created', [
            'user_id' => $user->id,
            'entity_type' => 'game',
            'entity_id' => $entityId,
            'status' => $status->value,
        ]);

        return new ShareIntentResult(true, 'games.detail');
    }

    /**
     * Process a campaign share intent.
     */
    private function processCampaignShareIntent(string $entityId, string $shareToken, $user): ShareIntentResult
    {
        $campaign = Campaign::find($entityId);

        if (! $campaign) {
            Log::warning('share_intent.entity_not_found', [
                'user_id' => $user->id,
                'entity_type' => 'campaign',
                'entity_id' => $entityId,
            ]);

            return new ShareIntentResult(false, null);
        }

        // Validate share token
        if (! $this->validateShareToken($campaign, $shareToken, 'campaign', $entityId, $user->id)) {
            return new ShareIntentResult(false, null);
        }

        // Check if user is already a participant
        $existing = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            Log::info('share_intent.already_participant', [
                'user_id' => $user->id,
                'entity_type' => 'campaign',
                'entity_id' => $entityId,
                'existing_status' => $existing->status->value,
            ]);

            return new ShareIntentResult(true, 'campaigns.detail');
        }

        // Determine status based on capacity
        $status = $this->determineStatus($campaign, 'campaign');

        // Create participant
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Invited->value,
            'status' => $status,
            'join_source' => JoinSource::ShareLink,
        ]);

        Log::info('share_intent.participant_created', [
            'user_id' => $user->id,
            'entity_type' => 'campaign',
            'entity_id' => $entityId,
            'status' => $status->value,
        ]);

        return new ShareIntentResult(true, 'campaigns.detail');
    }

    /**
     * Validate that the entity's share_token matches the token from the cookie.
     */
    private function validateShareToken($entity, string $token, string $entityType, string $entityId, string $userId): bool
    {
        if ($entity->share_token === null || $entity->share_token !== $token) {
            Log::warning('share_intent.token_mismatch', [
                'user_id' => $userId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return false;
        }

        if ($entity->share_token_expires_at !== null && $entity->share_token_expires_at->isPast()) {
            Log::warning('share_intent.token_expired', [
                'user_id' => $userId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'expires_at' => $entity->share_token_expires_at->toIso8601String(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Determine participant status based on entity capacity.
     *
     * Games → waitlisted when full. Campaigns → benched when full.
     */
    private function determineStatus($entity, string $entityType): ParticipantStatus
    {
        $approvedCount = $entity->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($entity->max_players && $approvedCount >= $entity->max_players) {
            return $entityType === 'game'
                ? ParticipantStatus::Waitlisted
                : ParticipantStatus::Benched;
        }

        return ParticipantStatus::Approved;
    }

    /**
     * Parse the share_intent cookie payload.
     *
     * Expected format: JSON string with entity_type, entity_id, share_token.
     */
    private function parsePayload(mixed $shareIntent): ?array
    {
        // The cookie may already be decoded as an array by Laravel's cookie handling
        if (is_array($shareIntent)) {
            if (! isset($shareIntent['entity_type'], $shareIntent['entity_id'], $shareIntent['share_token'])) {
                return null;
            }

            if (! in_array($shareIntent['entity_type'], ['game', 'campaign'], true)) {
                return null;
            }

            return $shareIntent;
        }

        if (! is_string($shareIntent)) {
            return null;
        }

        $data = json_decode($shareIntent, true);

        if (! is_array($data)) {
            return null;
        }

        if (! isset($data['entity_type'], $data['entity_id'], $data['share_token'])) {
            return null;
        }

        if (! in_array($data['entity_type'], ['game', 'campaign'], true)) {
            return null;
        }

        return $data;
    }

    /**
     * Clear the share_intent cookie from the response.
     */
    private function clearCookie(Response $response): Response
    {
        $response->headers->removeCookie('share_intent');
        $response->withCookie(cookie()->forget('share_intent'));

        return $response;
    }

    private function failResult(string $reason, string $userId, string $entityType, string $entityId): ShareIntentResult
    {
        Log::warning('share_intent.failed', [
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reason' => $reason,
        ]);

        return new ShareIntentResult(false, null);
    }

    /**
     * Determine if this request should be checked for share intent processing.
     *
     * Skip API calls, Livewire updates, and non-GET methods.
     */
    private function shouldProcess(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->is('api/*')
            && ! $request->header('X-Livewire');
    }
}

/**
 * Internal result object for share intent processing.
 */
class ShareIntentResult
{
    public function __construct(
        public readonly bool $shouldRedirect,
        public readonly ?string $redirectRoute,
    ) {}
}
