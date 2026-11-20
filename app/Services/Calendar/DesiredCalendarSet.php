<?php

namespace App\Services\Calendar;

use App\Enums\ParticipantStatus;
use App\Http\Controllers\ICalFeedController;
use App\Models\CampaignParticipant;
use App\Models\Event;
use App\Models\Game;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The schedulable-commitments query (M083/S03-T04) — the ONE definition
 * of "what belongs on this user's calendar", extracted verbatim from
 * ICalFeedController so the webcal feed and the Google reconcile sweep
 * consume the same query and can never disagree (D287: the feed is the
 * commitment authority; the sweep heals drift ON its semantics, it does
 * not get a second opinion).
 *
 * The SET is raw feed semantics: games the user hosts or is an approved
 * participant in, every session of a campaign they are an approved member
 * of, and events they hold a confirmed registration for — bounded to the
 * rolling feed window (today through +90 days, ICalFeedController::
 * feedWindow()). Cancelled occurrences are INCLUDED (the feed publishes
 * them STATUS:CANCELLED) — callers derive the per-entity action, this
 * class only owns the membership. Source toggles are likewise NOT
 * applied here: they are a Google-sync concern, so the reconcile job
 * subtracts disabled sources from this set instead of the feed baking
 * them in.
 */
class DesiredCalendarSet
{
    /**
     * Resolve the user's full desired set inside the feed's rolling
     * window: their schedulable commitments, split by entity type (the
     * consumers differ — the feed concats and orders, the sweep filters
     * per source toggle and maps to Google event ids).
     *
     * @return array{games: Collection<int, Game>, events: Collection<int, Event>}
     */
    public function forUser(User $user): array
    {
        [$windowStart, $windowEnd] = ICalFeedController::feedWindow();

        return [
            'games' => $this->gamesForUser($user, $windowStart, $windowEnd),
            'events' => $this->registeredEventsForUser($user, $windowEnd),
        ];
    }

    /**
     * Games within the window that the user hosts OR is an approved
     * participant in OR that are sessions of a campaign the user is an
     * approved member of (D287: campaign membership is a standing commitment
     * to the campaign's schedule — sessions land on the member's calendar
     * even when their per-session game-participant row is benched or
     * waitlisted by capacity routing).
     *
     * Includes canceled games so the feed can emit STATUS:CANCELLED for sync.
     *
     * @return Collection<int, Game>
     */
    public function gamesForUser(User $user, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        return Game::query()
            // Upper bound mirrors the events leg's inclusive community day
            // (+90 whole days): windowEnd is community MIDNIGHT of day +90,
            // so day +90's sessions need the end-of-day instant (midnight of
            // day +91) to match the docblock's "today through +90 inclusive"
            // and the events query's toDateString() comparison.
            ->whereBetween('date_time', [$windowStart, $windowEnd->copy()->addDay()->utc()])
            ->where(function ($query) use ($user) {
                // Games the user hosts.
                $query->whereBelongsTo($user, 'owner')
                    ->orWhereHas('participants', fn ($q) => $q
                        ->whereBelongsTo($user)
                        ->where('status', ParticipantStatus::Approved->value))
                    ->orWhereIn('campaign_id', $this->approvedCampaignIds($user));
            })
            // owner + linkedLocation are read per VEVENT by the renderer/
            // IcsBuilder — eager-loaded so the feed stays N+1-free.
            ->with(['owner', 'linkedLocation'])
            ->orderBy('date_time')
            ->get();
    }

    /**
     * Events within the window the user holds a CONFIRMED registration for
     * (D287): pending registrations are not commitments yet; cancelled ones
     * drop out of the feed entirely (the registration, not the event, is the
     * member's commitment — a cancelled event with a still-confirmed
     * registration publishes STATUS:CANCELLED so clients sync the removal).
     *
     * @return Collection<int, Event>
     */
    public function registeredEventsForUser(User $user, Carbon $windowEnd): Collection
    {
        return Event::query()
            ->whereHas('registrations', fn ($q) => $q
                ->whereBelongsTo($user)
                ->where('status', 'confirmed'))
            // start_date is a date column; compare against calendar dates on
            // the community wall calendar. The lower bound uses the COMMUNITY
            // today, not windowStart's UTC date: windowStart is Berlin
            // midnight converted to UTC (23:00Z the prior day in winter), so
            // its toDateString() would admit community-yesterday's events on
            // every render. The upper bound mirrors feedWindow()'s community
            // day +90.
            ->where('start_date', '>=', community_now()->toDateString())
            ->where('start_date', '<=', $windowEnd->toDateString())
            ->with('organizer')
            ->get();
    }

    /**
     * IDs of the campaigns the user is an approved member of.
     *
     * @return list<string>
     */
    private function approvedCampaignIds(User $user): array
    {
        $campaignIds = [];

        foreach (
            CampaignParticipant::query()
                ->whereBelongsTo($user)
                ->where('status', ParticipantStatus::Approved->value)
                ->pluck('campaign_id') as $campaignId
        ) {
            if (is_string($campaignId)) {
                $campaignIds[] = $campaignId;
            }
        }

        return $campaignIds;
    }
}
