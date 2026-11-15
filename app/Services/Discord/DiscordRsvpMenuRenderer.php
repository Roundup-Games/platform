<?php

namespace App\Services\Discord;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;

/**
 * Pure transformer that builds the per-clicker RSVP {@see DiscordRsvpMenu} from
 * the clicker's current roster state.
 *
 * Mirrors {@see DiscordCardRenderer}'s purity (MEM917): zero DB queries, zero
 * Discord I/O, zero filesystem access. The caller (the interactions controller)
 * resolves the clicker's current participant status and the roster counts
 * upstream, hands them in via {@see DiscordRsvpMenuContext}, and this renderer
 * maps them to the ephemeral menu the clicker sees.
 *
 * State matrix (what the clicker sees):
 *   owner                 → "You're hosting" (read-only, no action buttons)
 *   Approved              → "You're in" + Leave
 *   Waitlisted            → waitlist position + Leave waitlist
 *   Benched               → "On the bench" + Leave
 *   Pending               → "Pending invite" + Leave (cancel the pending RSVP)
 *   none + seats free     → "Claim your seat" + Join
 *   none + full           → "Full — join the waitlist" + Join (auto-waitlists)
 *   none + cancelled/done  → "No longer available" (read-only)
 *
 * All copy resolves from the notifications.[content|action|error]_rsvp_* lang
 * keys in the clicker's locale — the controller resolves the interaction
 * payload's locale field and carries it on the context (null → app locale).
 * Terminology follows the glossary canon (D174/D264): Game/Runde, cancelled
 * (EN-British), du-form DE, nachrücken for waitlist/bench promotion.
 *
 * The action buttons carry custom_ids the controller routes to deferred jobs:
 *   roundup:join:{gameId}  → ProcessDiscordRsvp (the existing join pipeline)
 *   roundup:leave:{gameId} → ProcessDiscordLeave (mirrors web leaveGame())
 */
class DiscordRsvpMenuRenderer
{
    /**
     * Render the per-clicker menu. The clicker may be null (unlinked) — callers
     * handle the unlinked deep-link case before invoking this, so this renderer
     * always receives a resolved clicker and their current status.
     */
    public function render(Game $game, DiscordRsvpMenuContext $context): DiscordRsvpMenu
    {
        $gameId = $game->id;
        $locale = $context->locale ?? app()->getLocale();

        // Terminal/non-joinable game state wins over everything: a cancelled or
        // completed game offers no join regardless of roster state.
        if ($game->status === GameStatus::Canceled || $game->status === GameStatus::Completed) {
            $key = $game->status === GameStatus::Canceled
                ? 'notifications.content_rsvp_game_cancelled'
                : 'notifications.content_rsvp_game_completed';

            return new DiscordRsvpMenu(
                content: __($key, [], $locale),
            );
        }

        // Owner: read-only acknowledgement. Hosts don't RSVP to their own games.
        if ($context->isOwner) {
            return new DiscordRsvpMenu(
                content: __('notifications.content_rsvp_hosting', [], $locale),
            );
        }

        // Already on the roster in some status → show their state + Leave.
        if ($context->currentStatus !== null) {
            return $this->menuForExistingParticipant($context, $gameId, $locale);
        }

        // Not on the roster → Join (auto-waitlists when full).
        return $this->menuForJoiner($context, $gameId, $locale);
    }

    /**
     * The clicker is already on the roster. Show their status + a Leave button.
     */
    private function menuForExistingParticipant(DiscordRsvpMenuContext $context, string $gameId, string $locale): DiscordRsvpMenu
    {
        $content = match ($context->currentStatus) {
            ParticipantStatus::Approved => $this->approvedContent($context, $locale),
            ParticipantStatus::Waitlisted => $this->waitlistedContent($context, $locale),
            ParticipantStatus::Benched => __('notifications.content_rsvp_benched', [], $locale),
            ParticipantStatus::Pending => __('notifications.content_rsvp_pending', [], $locale),
            // Removed/Rejected are not active participant states; treat as
            // not-on-roster. (The controller's status query filters to active
            // statuses, so this is defensive.)
            default => __('notifications.content_rsvp_not_on_roster', [], $locale),
        };

        // A participant whose status resolved to not-on-roster (Removed/Rejected)
        // gets a Join path, not Leave.
        $isActive = in_array($context->currentStatus, [
            ParticipantStatus::Approved,
            ParticipantStatus::Waitlisted,
            ParticipantStatus::Benched,
            ParticipantStatus::Pending,
        ], true);

        if (! $isActive) {
            return $this->menuForJoiner($context, $gameId, $locale);
        }

        return new DiscordRsvpMenu(
            content: $content,
            components: [$this->leaveRow($gameId, $context->currentStatus, $locale)],
        );
    }

    /**
     * The clicker is not on the roster (or was removed). Show Join (which
     * auto-waitlists when the roster is full).
     */
    private function menuForJoiner(DiscordRsvpMenuContext $context, string $gameId, string $locale): DiscordRsvpMenu
    {
        $max = $context->maxPlayers;
        $seatsLeft = ($max !== null && $max > 0) ? max(0, $max - $context->approvedCount) : null;

        if ($seatsLeft === null) {
            $content = __('notifications.content_rsvp_joiner_open_roster', ['approved' => $context->approvedCount], $locale);
        } elseif ($seatsLeft > 0) {
            $content = __('notifications.content_rsvp_joiner_seats_left', [
                'approved' => $context->approvedCount,
                'max' => $max,
                'left' => $seatsLeft,
            ], $locale);
        } else {
            $content = __('notifications.content_rsvp_joiner_full', ['max' => $max], $locale);
        }

        $joinLabel = ($seatsLeft !== null && $seatsLeft === 0)
            ? __('notifications.action_rsvp_join_waitlist', [], $locale)
            : __('notifications.action_rsvp_join', [], $locale);

        return new DiscordRsvpMenu(
            content: $content,
            components: [$this->joinRow($gameId, $joinLabel)],
        );
    }

    // ── Copy ────────────────────────────────────────────

    private function approvedContent(DiscordRsvpMenuContext $context, string $locale): string
    {
        $max = $context->maxPlayers;

        if ($max !== null && $max > 0) {
            return __('notifications.content_rsvp_approved_seat', [
                'current' => $context->approvedCount,
                'max' => $max,
            ], $locale);
        }

        return __('notifications.content_rsvp_approved', [], $locale);
    }

    private function waitlistedContent(DiscordRsvpMenuContext $context, string $locale): string
    {
        $pos = $context->waitlistPosition;

        if ($pos !== null && $pos > 0) {
            return __('notifications.content_rsvp_waitlisted_position', ['position' => $pos], $locale);
        }

        return __('notifications.content_rsvp_waitlisted', [], $locale);
    }

    // ── Button rows ─────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function joinRow(string $gameId, string $label): array
    {
        return [
            'type' => 1, // ACTION_ROW
            'components' => [
                [
                    'type' => 2, // BUTTON
                    'style' => 1, // PRIMARY (blurple)
                    'label' => $label,
                    'custom_id' => "roundup:join:{$gameId}",
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveRow(string $gameId, ParticipantStatus $status, string $locale): array
    {
        $label = match ($status) {
            ParticipantStatus::Waitlisted, ParticipantStatus::Pending => __('notifications.action_rsvp_leave_waitlist', [], $locale),
            default => __('notifications.action_rsvp_leave', [], $locale),
        };

        return [
            'type' => 1, // ACTION_ROW
            'components' => [
                [
                    'type' => 2, // BUTTON
                    'style' => 4, // DANGER (red)
                    'label' => $label,
                    'custom_id' => "roundup:leave:{$gameId}",
                ],
            ],
        ];
    }
}
