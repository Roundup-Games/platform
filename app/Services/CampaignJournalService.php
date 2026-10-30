<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CharacterStateChangeType;
use App\Enums\GameStatus;
use App\Enums\GoalStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use App\Models\Game;
use App\Models\User;
use App\Notifications\RecapPosted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Write path for the campaign journal (M071, D220).
 *
 * The journal's kind=recap entry is the ONLY recap record (the legacy
 * games.recap projection was culled with the other legacy recap paths):
 * every recap-reading consumer (action center, dashboards, activity
 * feed, session-end partial) resolves through the Game::recapEntry
 * relation, and the post-commit tail carries the dashboard-cache
 * invalidation that used to ride GameObserver's wasChanged('recap').
 *
 * Campaign sessions only (D218, M071/S03): one-shot games get the
 * Epilogue bulletin instead of a recap writer.
 */
class CampaignJournalService
{
    /**
     * Partial unique index that makes one recap per session unconditional
     * at the database level (see the journal-table migration).
     */
    private const ONE_RECAP_PER_SESSION_INDEX = 'campaign_journal_entries_one_recap_per_session';

    public function __construct(
        private ActivityLogService $activityLogService,
        private NotificationService $notificationService,
        private DashboardCacheService $cache,
    ) {}

    /**
     * Write a campaign session's recap as a journal entry.
     *
     * Creates the kind=recap, visibility=players journal entry in ONE
     * transaction, then runs the post-commit tail (dashboard caches,
     * SessionRecapped activity, RecapPosted notifications) exactly once
     * for the only recap write path.
     *
     * Validation rules keep the classic recap guards — completed game,
     * host-only, write-once, non-empty — with two journal deltas: the
     * session must belong to a campaign (one-shots have no recap writer,
     * M071/S03), and the body ceiling is 10000 characters (the journal
     * is the narrative home).
     *
     * @throws \LogicException When any validation fails (translated
     *                         messages) or a concurrent write wins the
     *                         one-recap-per-session race.
     */
    public function writeRecap(Game $game, User $author, string $body): CampaignJournalEntry
    {
        if ($game->status !== GameStatus::Completed) {
            throw new \LogicException(__('games.error_recap_game_not_completed'));
        }

        if ($game->campaign_id === null) {
            // D218: the journal is the campaign record — one-shot games
            // get the Epilogue bulletin instead (M071/S03).
            throw new \LogicException(__('journal.error_not_campaign_session'));
        }

        if ($game->owner_id !== $author->id) {
            throw new \LogicException(__('games.error_recap_not_host'));
        }

        if (mb_strlen($body) > 10000) {
            throw new \LogicException(__('journal.error_recap_too_long'));
        }

        if (trim($body) === '') {
            throw new \LogicException(__('games.error_recap_empty'));
        }

        // Already-written: one recap per session is also structurally
        // enforced by the partial unique index — this check is the
        // friendly face of the same rule.
        if ($game->recapEntry()->exists()) {
            throw new \LogicException(__('games.error_recap_already_written'));
        }

        try {
            $entry = DB::transaction(function () use ($game, $author, $body): CampaignJournalEntry {
                // Create through the session relation (Eloquent best-practice
                // R2): game_id is plumbed by the relation, not by hand.
                $entry = $game->journalEntries()->create([
                    'campaign_id' => $game->campaign_id,
                    'author_user_id' => $author->id,
                    'kind' => JournalEntryKind::Recap,
                    'visibility' => JournalEntryVisibility::Players,
                    'body' => $body,
                ]);

                return $entry;
            });
        } catch (QueryException $e) {
            // The application-level check above narrows the race; the
            // partial unique index is the unconditional backstop. Rethrow
            // the losing concurrent write as the already-written error so
            // callers see one shape.
            if (($e->errorInfo[0] ?? null) === '23505'
                && str_contains($e->getMessage(), self::ONE_RECAP_PER_SESSION_INDEX)) {
                throw new \LogicException(__('games.error_recap_already_written'));
            }

            throw $e;
        }

        Log::info('Recap written for game', [
            'game_id' => $game->id,
            'campaign_id' => $game->campaign_id,
            'author_id' => $author->id,
            'content_length' => mb_strlen($body),
        ]);

        // The post-commit tail is isolated on purpose: it runs AFTER the
        // entry committed, so a failure there must never 500 the host's
        // write action — the recap IS written, and a retry would only
        // hit the already-written refusal. It carries what used to ride
        // GameObserver's wasChanged('recap') hook: dashboard caches,
        // activity log, notifications.
        try {
            $this->invalidateRecapCaches($game, $author);
            $this->announceRecap($game, $author);
        } catch (\Throwable $e) {
            report($e);
            Log::error('journal.recap_announce_failed', [
                'game_id' => $game->id,
                'campaign_id' => (string) $game->campaign_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        return $entry;
    }

    /**
     * Write an in-character journal entry (M071/S02, spec §2).
     *
     * Any active participant — Approved or Benched; the owner's own
     * participant row counts — may post in-character between sessions
     * (interlude entries: game_id stays null; session-scoped authoring is
     * the deferred session-end widget surface). Visibility is players.
     *
     * @throws \LogicException When any validation fails (translated
     *                         messages).
     */
    public function writeInCharacter(Campaign $campaign, CampaignParticipant $author, string $body, ?string $inGameDate = null): CampaignJournalEntry
    {
        if ($author->campaign_id !== $campaign->id) {
            throw new \LogicException(__('journal.error_not_participant'));
        }

        if (! in_array($author->status, [ParticipantStatus::Approved, ParticipantStatus::Benched], true)) {
            throw new \LogicException(__('journal.error_participant_not_active'));
        }

        if (mb_strlen($body) > 10000) {
            throw new \LogicException(__('journal.error_body_too_long'));
        }

        if (trim($body) === '') {
            throw new \LogicException(__('journal.error_body_empty'));
        }

        // Whitespace-only input normalizes to null: the blade's
        // in_game_date chip renders on truthiness, and '   ' would
        // store as a non-null date-less string (empty chip).
        $inGameDate = $inGameDate !== null && trim($inGameDate) === '' ? null : $inGameDate;

        if ($inGameDate !== null && mb_strlen($inGameDate) > 100) {
            throw new \LogicException(__('journal.error_in_game_date_too_long'));
        }

        // Single-row create — no transaction needed (no multi-row
        // consistency at stake). campaign_id is plumbed by the relation.
        $entry = $campaign->journalEntries()->create([
            'author_user_id' => $author->user_id,
            'author_participant_id' => $author->id,
            'kind' => JournalEntryKind::InCharacter,
            'visibility' => JournalEntryVisibility::Players,
            'body' => $body,
            'in_game_date' => $inGameDate,
        ]);

        Log::info('In-character journal entry written', [
            'campaign_id' => $campaign->id,
            'author_user_id' => $author->user_id,
            'content_length' => mb_strlen($body),
        ]);

        return $entry;
    }

    /**
     * Write a GM prep note (M071/S02, spec §2).
     *
     * Campaign owner only. The visibility=gms default IS the security
     * contract: prep notes are invisible to players in every filter —
     * pinned by test. Interlude entries: game_id stays null.
     *
     * @throws \LogicException When any validation fails (translated
     *                         messages).
     */
    public function writePrepNote(Campaign $campaign, User $author, string $body, ?string $inGameDate = null): CampaignJournalEntry
    {
        if ($campaign->owner_id !== $author->id) {
            throw new \LogicException(__('journal.error_not_campaign_owner'));
        }

        if (mb_strlen($body) > 10000) {
            throw new \LogicException(__('journal.error_body_too_long'));
        }

        if (trim($body) === '') {
            throw new \LogicException(__('journal.error_body_empty'));
        }

        // Whitespace-only input normalizes to null (see writeInCharacter).
        $inGameDate = $inGameDate !== null && trim($inGameDate) === '' ? null : $inGameDate;

        if ($inGameDate !== null && mb_strlen($inGameDate) > 100) {
            throw new \LogicException(__('journal.error_in_game_date_too_long'));
        }

        $entry = $campaign->journalEntries()->create([
            'author_user_id' => $author->id,
            'kind' => JournalEntryKind::PrepNote,
            'visibility' => JournalEntryVisibility::Gms,
            'body' => $body,
            'in_game_date' => $inGameDate,
        ]);

        Log::info('GM prep note written', [
            'campaign_id' => $campaign->id,
            'author_id' => $author->id,
            'content_length' => mb_strlen($body),
        ]);

        return $entry;
    }

    /**
     * Create a campaign goal (M071/S02, spec §2).
     *
     * Owner-only authoring; the board groups by status and orders by
     * sort. sort is max+1 scoped to the campaign, re-queried under the
     * transaction (ties are cosmetic — no unique constraint on sort).
     *
     * @throws \LogicException When any validation fails (translated
     *                         messages).
     */
    public function createGoal(Campaign $campaign, User $author, string $title, ?string $description = null, GoalStatus $status = GoalStatus::Planned): CampaignGoal
    {
        if ($campaign->owner_id !== $author->id) {
            throw new \LogicException(__('journal.error_not_campaign_owner'));
        }

        if (mb_strlen($title) > 200) {
            throw new \LogicException(__('journal.error_title_too_long'));
        }

        if (trim($title) === '') {
            throw new \LogicException(__('journal.error_title_required'));
        }

        if ($description !== null && mb_strlen($description) > 1000) {
            throw new \LogicException(__('journal.error_description_too_long'));
        }

        $goal = DB::transaction(function () use ($campaign, $title, $description, $status): CampaignGoal {
            // ->max('sort') returns the raw aggregate or null when the
            // campaign has no goals yet. Narrow with is_numeric() so the
            // (int) cast gets a typed operand (RecurrenceService pattern).
            $maxSort = $campaign->goals()->max('sort');
            $sort = (is_numeric($maxSort) ? (int) $maxSort : 0) + 1;

            return $campaign->goals()->create([
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'sort' => $sort,
            ]);
        });

        Log::info('Campaign goal created', [
            'campaign_id' => $campaign->id,
            'goal_id' => $goal->id,
            'status' => $status->value,
        ]);

        return $goal;
    }

    /**
     * Change a goal's status (M071/S02, spec §2).
     *
     * Owner-only. status_changed_at_game_id is the S04 "goals moved
     * since last session" diff source: the explicit $atGame wins, else
     * the campaign's latest completed session (max date_time), else
     * null — the fallback resolves even when called from the tab
     * without session context. Unchanged statuses are a no-op so a
     * re-click never restamps the goal.
     *
     * @throws \LogicException When the author is not the campaign owner.
     */
    public function changeGoalStatus(CampaignGoal $goal, User $author, GoalStatus $newStatus, ?Game $atGame = null): CampaignGoal
    {
        $ownerId = Campaign::query()->whereKey($goal->campaign_id)->value('owner_id');

        // value() returns mixed; is_string narrows before the strict
        // compare (a null/odd driver value simply fails the check).
        if (! is_string($ownerId) || $ownerId !== $author->id) {
            throw new \LogicException(__('journal.error_not_campaign_owner'));
        }

        // An explicit stamp game must belong to the goal's campaign —
        // a foreign session's id would silently drop the goal out of the
        // Previously On "goals moved" scan (it keys on this stamp).
        if ($atGame !== null && $atGame->campaign_id !== $goal->campaign_id) {
            throw new \LogicException(__('journal.error_not_campaign_session'));
        }

        if ($goal->status === $newStatus) {
            return $goal;
        }

        $from = $goal->status;

        $goal = DB::transaction(function () use ($goal, $newStatus, $atGame): CampaignGoal {
            // Resolved under the transaction so the fallback reads the
            // committed latest completed session, not a stale one.
            $stampGameId = $atGame !== null
                ? $atGame->id
                : Game::query()
                    ->where('campaign_id', $goal->campaign_id)
                    ->where('status', GameStatus::Completed)
                    ->orderByDesc('date_time')
                    ->value('id');

            $goal->update([
                'status' => $newStatus,
                'status_changed_at_game_id' => $stampGameId,
            ]);

            return $goal;
        });

        Log::info('Campaign goal status changed', [
            'goal_id' => $goal->id,
            'from' => $from->value,
            'to' => $newStatus->value,
            'status_changed_at_game_id' => $goal->status_changed_at_game_id,
        ]);

        return $goal;
    }

    /**
     * Log a character state change (M071/S02, spec §2).
     *
     * Owner-only: goal/state updates are GM session-flow actions (spec
     * §1); player self-logging is a later opt-in. $author is the GM
     * writing the row; $participant is the CHARACTER the change belongs
     * to (any campaign member — the Journal tab's per-participant form).
     *
     * @throws \LogicException When any validation fails (translated
     *                         messages).
     */
    public function logStateChange(Campaign $campaign, User $author, CampaignParticipant $participant, CharacterStateChangeType $type, string $delta, ?Game $game = null): CharacterStateChange
    {
        if ($campaign->owner_id !== $author->id) {
            throw new \LogicException(__('journal.error_not_campaign_owner'));
        }

        if ($participant->campaign_id !== $campaign->id) {
            throw new \LogicException(__('journal.error_participant_not_in_campaign'));
        }

        // The same seated-member guard writeInCharacter enforces: the GM
        // may only stamp changes onto characters that are actually at
        // the table — a pending/waitlisted/removed row must never grow
        // state history (it would surface in the S04 loose-threads scan).
        if (! in_array($participant->status, [ParticipantStatus::Approved, ParticipantStatus::Benched], true)) {
            throw new \LogicException(__('journal.error_participant_not_seated'));
        }

        // A stamped session must belong to this campaign — same guard
        // writeRecap enforces; a foreign game_id would cross-link state
        // history to a session this campaign never ran (the timeline and
        // the D232 bounds key on the pair).
        if ($game !== null && $game->campaign_id !== $campaign->id) {
            throw new \LogicException(__('journal.error_not_campaign_session'));
        }

        if (mb_strlen($delta) > 2000) {
            throw new \LogicException(__('journal.error_delta_too_long'));
        }

        if (trim($delta) === '') {
            throw new \LogicException(__('journal.error_delta_empty'));
        }

        $change = $campaign->stateChanges()->create([
            'participant_id' => $participant->id,
            'game_id' => $game?->id,
            'change_type' => $type,
            'delta' => $delta,
        ]);

        Log::info('Character state change logged', [
            'campaign_id' => $campaign->id,
            'participant_id' => $participant->id,
            'change_type' => $type->value,
        ]);

        return $change;
    }

    /**
     * The recap write's dashboard-cache invalidation (the tail that used
     * to fire from GameObserver::saved on wasChanged('recap')): the
     * author's and every approved participant's contributions and recaps
     * sections.
     */
    private function invalidateRecapCaches(Game $game, User $author): void
    {
        $userIds = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('user_id')
            ->push($game->owner_id)
            ->unique()
            ->map(fn (mixed $id): string => to_string_id($id))
            ->all();

        $this->cache->invalidateForUsers($userIds, ['contributions', 'recaps']);

        // The recap write used to be a games-table update, so
        // GameObserver::saved's game-event invalidation (action center
        // included) fired with it. The journal write touches no game
        // row — carry the same invalidation here.
        $this->cache->invalidateForGameEvent($game, 'recap');
        $this->cache->invalidateActionCenterForGameEvent($game->id);
    }

    /**
     * The shared recap announce effects (formerly RecapService, folded
     * here when the legacy recap paths were culled): one SessionRecapped
     * activity entry and one RecapPosted notification per approved
     * participant (never the author).
     */
    private function announceRecap(Game $game, User $author): void
    {
        $this->activityLogService->log(
            ActivityType::SessionRecapped,
            $author,
            $game,
            ['game_id' => $game->id, 'author_id' => $author->id],
        );

        $participants = $game->participants()
            ->where('status', 'approved')
            ->where('user_id', '!=', $author->id)
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        foreach ($participants as $participant) {
            if (! ($participant instanceof User)) {
                continue;
            }

            try {
                $this->notificationService->send(
                    $participant,
                    new RecapPosted($game, $author),
                    NotificationCategory::SessionContent,
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send recap notification', [
                    'game_id' => $game->id,
                    'participant_id' => $participant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
