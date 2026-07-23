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
 *   none + canceled/done  → "No longer available" (read-only)
 *
 * The action buttons carry custom_ids the controller routes to deferred jobs:
 *   roundup:join:{gameId}  → ProcessDiscordRsvp (the existing join pipeline)
 *   roundup:leave:{gameId} → ProcessDiscordLeave (mirrors web leaveGame())
 *
 * Hardcoded English copy to match DiscordRsvpOutcome / the unlinked-deep-link
 * precedent (localization is a follow-up; the menu does not carry locale).
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
        $gameId = (string) $game->id;
        $max = $context->maxPlayers;

        // Terminal/non-joinable game state wins over everything: a canceled or
        // completed game offers no join regardless of roster state.
        if ($game->status === GameStatus::Canceled || $game->status === GameStatus::Completed) {
            $verb = $game->status === GameStatus::Canceled ? 'canceled' : 'completed';

            return new DiscordRsvpMenu(
                content: "This session has been {$verb} — check roundup for the latest.",
            );
        }

        // Owner: read-only acknowledgement. Hosts don't RSVP to their own games.
        if ($context->isOwner) {
            return new DiscordRsvpMenu(
                content: "🎲 You're hosting this session. Manage the roster and details on roundup.",
            );
        }

        // Already on the roster in some status → show their state + Leave.
        if ($context->currentStatus !== null) {
            return $this->menuForExistingParticipant($context, $gameId);
        }

        // Not on the roster → Join (auto-waitlists when full).
        return $this->menuForJoiner($context, $gameId);
    }

    /**
     * The clicker is already on the roster. Show their status + a Leave button.
     */
    private function menuForExistingParticipant(DiscordRsvpMenuContext $context, string $gameId): DiscordRsvpMenu
    {
        $content = match ($context->currentStatus) {
            ParticipantStatus::Approved => $this->approvedContent($context),
            ParticipantStatus::Waitlisted => $this->waitlistedContent($context),
            ParticipantStatus::Benched => "🏋️ You're on the bench — we'll promote you here the moment a seat frees up.",
            ParticipantStatus::Pending => '⏳ Your RSVP is pending. Leave to cancel it, or hold tight.',
            // Removed/Rejected are not active participant states; treat as
            // not-on-roster. (The controller's status query filters to active
            // statuses, so this is defensive.)
            default => "You're not currently on the roster for this game.",
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
            return $this->menuForJoiner($context, $gameId);
        }

        return new DiscordRsvpMenu(
            content: $content,
            components: [$this->leaveRow($gameId, $context->currentStatus)],
        );
    }

    /**
     * The clicker is not on the roster (or was removed). Show Join (which
     * auto-waitlists when the session is full).
     */
    private function menuForJoiner(DiscordRsvpMenuContext $context, string $gameId): DiscordRsvpMenu
    {
        $max = $context->maxPlayers;
        $seatsLeft = ($max !== null && $max > 0) ? max(0, $max - $context->approvedCount) : null;

        if ($seatsLeft === null) {
            $content = "🎟️ {$context->approvedCount} joined · open roster — claim your seat.";
        } elseif ($seatsLeft > 0) {
            $content = "🎟️ {$context->approvedCount}/{$max} seats · {$seatsLeft} left — claim yours.";
        } else {
            $content = "🎟️ {$max}/{$max} seats — full. Join to grab a waitlist spot; we'll bump you up the moment a seat opens.";
        }

        $joinLabel = ($seatsLeft !== null && $seatsLeft === 0) ? 'Join waitlist' : '✅ Join';

        return new DiscordRsvpMenu(
            content: $content,
            components: [$this->joinRow($gameId, $joinLabel)],
        );
    }

    // ── Copy ────────────────────────────────────────────

    private function approvedContent(DiscordRsvpMenuContext $context): string
    {
        $max = $context->maxPlayers;
        $seat = $max !== null && $max > 0
            ? " (seat {$context->approvedCount} of {$max})"
            : '';

        return "✅ You're in{$seat} — your seat is saved. 🎲";
    }

    private function waitlistedContent(DiscordRsvpMenuContext $context): string
    {
        $pos = $context->waitlistPosition;

        return $pos !== null && $pos > 0
            ? "📋 You're #{$pos} on the waitlist — we'll bump you up here the moment a seat opens."
            : "📋 You're on the waitlist — we'll bump you up here the moment a seat opens.";
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
    private function leaveRow(string $gameId, ParticipantStatus $status): array
    {
        $label = match ($status) {
            ParticipantStatus::Waitlisted, ParticipantStatus::Pending => 'Leave waitlist',
            default => '🚪 Leave',
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
