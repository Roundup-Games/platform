<?php

namespace App\Http\Middleware;

use App\Dto\ShareIntentResult;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            return $this->clearCookie($next($request));
        }

        // Process the share intent BEFORE running $next($request) so we can
        // redirect immediately without wasting a full controller/render cycle.
        $result = $this->processShareIntent($payload, $user);

        // If we created a participant (or user was already one), redirect now
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

        // No redirect needed — run the normal request pipeline and clear cookie
        return $this->clearCookie($next($request));
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

        // Owner is already a participant — skip with redirect
        if ($game->owner_id === $user->id) {
            Log::debug('share_intent.is_owner', [
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'user_id' => $user->id,
            ]);

            return new ShareIntentResult(true, 'games.show');
        }

        // Check if user is already a participant (before acquiring lock)
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
            return new ShareIntentResult(true, 'games.show');
        }

        // Wrap participant creation in a transaction with lockForUpdate to prevent
        // race conditions (concurrent share-link joins, double-clicks, etc.)
        try {
            $status = null;

            DB::transaction(function () use ($game, $user, &$status) {
                $lockedGame = Game::lockForUpdate()->find($game->id);

                // Check game is still active (not completed/cancelled)
                if (in_array($lockedGame->status, [GameStatus::Completed, GameStatus::Canceled], true)) {
                    Log::warning('share_intent.game_inactive', [
                        'user_id' => $user->id,
                        'entity_id' => $game->id,
                        'status' => $lockedGame->status->value,
                    ]);

                    return; // exits transaction early — $status stays null
                }

                // Re-check under lock that user hasn't been added by a concurrent request
                $alreadyExists = GameParticipant::where('game_id', $lockedGame->id)
                    ->where('user_id', $user->id)
                    ->exists();

                if ($alreadyExists) {
                    // Race lost — another request already added the participant.
                    // Set status so we still redirect.
                    $status = ParticipantStatus::Approved;

                    return;
                }

                // Determine status based on capacity (under lock)
                $status = $this->determineStatus($lockedGame, 'game');

                $participantData = [
                    'game_id' => $lockedGame->id,
                    'user_id' => $user->id,
                    'role' => 'player',
                    'status' => $status,
                    'join_source' => JoinSource::ShareLink,
                ];

                if ($status === ParticipantStatus::Waitlisted) {
                    $participantData['waitlisted_at'] = now();
                } elseif ($status === ParticipantStatus::Benched) {
                    $participantData['benched_at'] = now();
                }

                GameParticipant::create($participantData);

                Log::info('share_intent.participant_created', [
                    'user_id' => $user->id,
                    'entity_type' => 'game',
                    'entity_id' => $lockedGame->id,
                    'status' => $status->value,
                ]);
            });
        } catch (QueryException $e) {
            // Unique constraint violation — participant already exists from a
            // concurrent request. The outcome is correct, so log and redirect.
            Log::warning('share_intent.duplicate_participant', [
                'user_id' => $user->id,
                'entity_type' => 'game',
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return new ShareIntentResult(true, 'games.show');
        }

        // If status is null the game was inactive — don't redirect, just clear cookie
        if ($status === null) {
            return new ShareIntentResult(false, null);
        }

        return new ShareIntentResult(true, 'games.show');
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

        // Owner is already a participant — skip with redirect
        if ($campaign->owner_id === $user->id) {
            Log::debug('share_intent.is_owner', [
                'entity_type' => 'campaign',
                'entity_id' => $campaign->id,
                'user_id' => $user->id,
            ]);

            return new ShareIntentResult(true, 'campaigns.show');
        }

        // Check if user is already a participant (before acquiring lock)
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

            return new ShareIntentResult(true, 'campaigns.show');
        }

        // Wrap participant creation in a transaction with lockForUpdate to prevent
        // race conditions (concurrent share-link joins, double-clicks, etc.)
        try {
            $status = null;

            DB::transaction(function () use ($campaign, $user, &$status) {
                $lockedCampaign = Campaign::lockForUpdate()->find($campaign->id);

                // Check campaign is still active (not cancelled/completed)
                if (in_array($lockedCampaign->status, [CampaignStatus::Cancelled, CampaignStatus::Completed], true)) {
                    Log::warning('share_intent.campaign_inactive', [
                        'user_id' => $user->id,
                        'entity_id' => $campaign->id,
                        'status' => $lockedCampaign->status->value,
                    ]);

                    return; // exits transaction early — $status stays null
                }

                // Re-check under lock that user hasn't been added by a concurrent request
                $alreadyExists = CampaignParticipant::where('campaign_id', $lockedCampaign->id)
                    ->where('user_id', $user->id)
                    ->exists();

                if ($alreadyExists) {
                    // Race lost — another request already added the participant.
                    // Set status so we still redirect.
                    $status = ParticipantStatus::Approved;

                    return;
                }

                // Determine status based on capacity (under lock)
                $status = $this->determineStatus($lockedCampaign, 'campaign');

                $participantData = [
                    'campaign_id' => $lockedCampaign->id,
                    'user_id' => $user->id,
                    'role' => 'player',
                    'status' => $status,
                    'join_source' => JoinSource::ShareLink,
                ];

                if ($status === ParticipantStatus::Waitlisted) {
                    $participantData['waitlisted_at'] = now();
                } elseif ($status === ParticipantStatus::Benched) {
                    $participantData['benched_at'] = now();
                }

                CampaignParticipant::create($participantData);

                Log::info('share_intent.participant_created', [
                    'user_id' => $user->id,
                    'entity_type' => 'campaign',
                    'entity_id' => $lockedCampaign->id,
                    'status' => $status->value,
                ]);
            });
        } catch (QueryException $e) {
            // Unique constraint violation — participant already exists from a
            // concurrent request. The outcome is correct, so log and redirect.
            Log::warning('share_intent.duplicate_participant', [
                'user_id' => $user->id,
                'entity_type' => 'campaign',
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return new ShareIntentResult(true, 'campaigns.show');
        }

        // If status is null the campaign was inactive — don't redirect, just clear cookie
        if ($status === null) {
            return new ShareIntentResult(false, null);
        }

        return new ShareIntentResult(true, 'campaigns.show');
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
     * Games → benched when full + campaign session, waitlisted when full + standalone.
     * Campaigns → benched when full.
     */
    private function determineStatus($entity, string $entityType): ParticipantStatus
    {
        $approvedCount = $entity->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($entity->max_players && $approvedCount >= $entity->max_players) {
            if ($entityType === 'campaign') {
                return ParticipantStatus::Benched;
            }

            // Game: bench if it belongs to a campaign, waitlist otherwise
            return $entity->campaign_id !== null
                ? ParticipantStatus::Benched
                : ParticipantStatus::Waitlisted;
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
