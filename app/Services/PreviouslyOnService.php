<?php

namespace App\Services;

use App\Enums\CharacterStateChangeType;
use App\Enums\GameStatus;
use App\Enums\GoalStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Campaign;
use App\Models\CharacterStateChange;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\PreviouslyOnDraft;
use App\Models\User;
use App\Support\TableLocale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Previously On composer and publisher (M071/S04,
 * docs/spec/campaign-journal-concept.md §3, D221).
 *
 * Draft-first by design: composeBody is pure composition from journal
 * data, upsertUnpublished persists the result as a previously_on_drafts
 * row (NEVER a GameBulletin — creation would fire the observer's whole
 * D185 fan-out), and publish turns a reviewed draft into exactly one
 * GameBulletin whose untouched observer delivers the full channel matrix
 * (board, in-app/mail/push/Discord per prefs, Discord teasers to session
 * threads when a guild is linked).
 *
 * Composition reads the journal only (D220: journal entries are the
 * source of truth — never games.recap, never prep-note or in-character
 * bodies) and keys off campaign_id only (D218: every campaign type gets
 * Previously On identically).
 */
class PreviouslyOnService
{
    /**
     * Recap excerpt length in words (concept §3.1: "first ~200 words").
     */
    private const EXCERPT_WORDS = 200;

    /**
     * Goal symbol map (concept §3.1): achieved ✔ / failed ✖ / everything
     * else ●.
     *
     * @var array<string, string>
     */
    private const GOAL_SYMBOLS = [
        'achieved' => '✔',
        'failed' => '✖',
    ];

    /**
     * Compose the Previously On digest body for a campaign's upcoming
     * session (concept §3.1): title, latest recap excerpt with a read-full
     * link, goals moved by the last completed session, loose threads, and
     * a next-session CTA. Each content section is omitted when empty; when
     * ALL of them are empty there is nothing to say and null comes back —
     * no junk CTA-only drafts (the 24h refresh hook relies on this).
     *
     * The body is PLAIN TEXT with \n line breaks only: the bulletin board
     * renders escaped plain text and the Discord teaser neutralizes masked
     * links — never markdown or HTML.
     *
     * Copy is resolved under the game's language (app fallback otherwise,
     * PublishGameBulletinToDiscord::locale() resolution), so the digest is
     * composed in the language the table plays in. The app locale is
     * restored in a finally (K002: format_date and the enum labels read it).
     */
    public function composeBody(Campaign $campaign, Game $nextSession): ?string
    {
        $locale = TableLocale::resolve($nextSession->language ?? null);

        $previous = App::getLocale();

        App::setLocale($locale);

        try {
            $sections = [];

            // ── Recap excerpt (D220: journal entries, never games.recap,
            // never prep-note or in-character bodies) ─────────────
            // Session-chronology pick, not write-order: a GM backfilling
            // an older session's recap after writing the newer one (or the
            // legacy backfill, whose created_at inherits game timestamps)
            // must never hijack the excerpt. Session recaps (always
            // game-bound — writeRecap creates through the session relation)
            // order by the session's date_time; a session-less interlude
            // recap only feeds the digest when no session recap exists at
            // all (NULLS LAST). id (UUIDv7) breaks same-session ties
            // deterministically.
            // visibility=players pinned (defense-in-depth): today every
            // recap writer forces Players, but the CHECK constraint
            // permits kind=recap/visibility=gms — a future writer must
            // not leak GM-only text into a bulletin delivered to every
            // approved participant.
            $recap = $campaign->journalEntries()
                ->where('kind', JournalEntryKind::Recap)
                ->where('campaign_journal_entries.visibility', JournalEntryVisibility::Players->value)
                ->leftJoin('games', 'games.id', '=', 'campaign_journal_entries.game_id')
                ->select('campaign_journal_entries.*')
                ->orderByRaw('games.date_time desc nulls last')
                ->orderByDesc('campaign_journal_entries.id')
                ->first();

            if ($recap !== null) {
                // The excerpt stands alone (D231): one bulletin = one body,
                // so a journal deep-link would dead-end for session-only
                // guests (viewJournal denies them) — the RSVP link to the
                // session page below closes the digest for EVERY recipient.
                $sections[] = Str::words($recap->body, self::EXCERPT_WORDS);
            }

            // ── Goals moved: stamps on the LAST completed session ────
            $lastCompleted = $campaign->sessions()
                ->where('status', GameStatus::Completed)
                ->orderByDesc('date_time')
                ->first();

            $movedGoals = $lastCompleted !== null
                ? $campaign->goals()
                    ->where('status_changed_at_game_id', $lastCompleted->id)
                    ->orderBy('sort')
                    ->get()
                : collect();

            if ($movedGoals->isNotEmpty()) {
                $goalLines = [__('journal.section_goals_moved', [], $locale)];

                // Group by GoalStatus->value, never enum instance keys
                // (MEM1378); iterate the enum's canonical order.
                $grouped = [];
                foreach ($movedGoals as $goal) {
                    $grouped[$goal->status->value][] = $goal;
                }

                foreach (GoalStatus::cases() as $status) {
                    foreach ($grouped[$status->value] ?? [] as $goal) {
                        $symbol = self::GOAL_SYMBOLS[$status->value] ?? '●';
                        $goalLines[] = $symbol.' '.$status->label().': "'.$goal->title.'"';
                    }
                }

                $sections[] = implode("\n", $goalLines);
            }

            // ── Loose threads: latest condition + latest item per
            // participant (the character's current state) ─────────────
            $threads = $this->latestConditionAndItemChanges($campaign);

            if ($threads->isNotEmpty()) {
                $threadLines = [__('journal.section_loose_threads', [], $locale)];

                foreach ($threads as $change) {
                    // PHP's nullsafe short-circuits the whole chain when the
                    // participant is gone; ->name on the surviving user is
                    // never null (label fallback covers a vanished user).
                    $name = $change->participant?->user->name
                        ?? __('journal.label_unknown_author', [], $locale);

                    $threadLines[] = $name.' — '.$change->change_type->label().': '.$change->delta;
                }

                $sections[] = implode("\n", $threadLines);
            }

            // Nothing to say: no junk CTA-only drafts.
            if ($sections === []) {
                return null;
            }

            // ── Next session CTA (always present once anything above
            // exists; the session link is the RSVP surface) ───────────
            $ctaLines = [__('journal.section_next_session', [], $locale)];
            $ctaLines[] = format_date($nextSession->date_time, 'weekday_datetime');

            // location is array<string, mixed>|null: narrow the details
            // entry instead of casting mixed to string.
            $locationDetails = $nextSession->location['details'] ?? '';
            if (is_string($locationDetails) && $locationDetails !== '') {
                $ctaLines[] = $locationDetails;
            }

            $ctaLines[] = route('games.show', ['locale' => $locale, 'id' => $nextSession]);

            $sections[] = implode("\n", $ctaLines);

            $completedCount = $campaign->sessions()
                ->where('status', GameStatus::Completed)
                ->count();

            $campaignName = $campaign->getTranslation('name', $locale);

            $title = __('journal.title_previously_on', [
                'campaign' => is_string($campaignName) ? $campaignName : '',
                'session' => $completedCount,
            ], $locale);

            return $title."\n\n".implode("\n\n", $sections);
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Compose or refresh the campaign's unpublished draft for a target
     * session (refresh-unpublished-or-compose, D221).
     *
     * The member gate is NOT here — call sites own it (the publish trigger
     * and the 24h reminder hook check membership first).
     *
     * Semantics:
     *  - any other unpublished draft of the campaign is superseded
     *    (deleted) first — see the D230 note below;
     *  - a draft for the session that is already published → null, never
     *    touched (published drafts are immutable);
     *  - a null composition with no existing row → null, nothing written;
     *  - a null composition with an existing unpublished row → the row is
     *    left as-is (nothing to refresh does not mean "blank the draft");
     *  - otherwise upsert on game_id: the first compose creates, later
     *    refreshes update the body in place (unique game_id, never a
     *    duplicate row).
     */
    public function upsertUnpublished(Campaign $campaign, Game $nextSession): ?PreviouslyOnDraft
    {
        // The D230 supersedes-delete runs INSIDE the transaction below
        // (under the campaign-row lock): outside it, two concurrent
        // composes for different sessions — e.g. the session-edit
        // recompose racing the 24h reminder — could both delete-then-
        // create and both survive, leaving two unpublished drafts. The
        // unique(game_id) index only prevents same-session duplicates,
        // so the campaign row is the shared serialization anchor.

        $existing = PreviouslyOnDraft::query()
            ->whereBelongsTo($nextSession)
            ->first();

        if ($existing !== null && $existing->published_at !== null) {
            return null;
        }

        $body = $this->composeBody($campaign, $nextSession);

        if ($body === null) {
            return $existing;
        }

        // The published guard re-checked UNDER LOCK: publish() flips
        // published_at in its own transaction, and an unlocked
        // updateOrCreate could overwrite the audit-trail body of a draft
        // whose bulletin already went out (a GM clicking Publish while a
        // reminder recompose races). The row lock serializes the two —
        // whoever commits second sees the published row and stands down.
        try {
            $draft = DB::transaction(function () use ($campaign, $nextSession, $body): ?PreviouslyOnDraft {
                // Serialize every compose trigger for this campaign on
                // the campaign row (publish() locks only the draft row —
                // no lock-order inversion, no deadlock). Held for the
                // delete + upsert pair below, making D230's at-most-one
                // unpublished draft atomic.
                Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();

                // D230 projection model: a campaign carries at most ONE
                // unpublished draft — the forward-looking one for its
                // next session. Composing for a target session supersedes
                // (deletes) every OTHER unpublished draft of the campaign,
                // so drafts can neither orphan nor accumulate; published
                // rows are immutable history and are never touched here.
                // This runs on every compose trigger (session create, 24h
                // reminder, session-edit recompose), keeping the
                // invariant true no matter who composes.
                PreviouslyOnDraft::query()
                    ->whereBelongsTo($campaign)
                    ->where('game_id', '!=', $nextSession->id)
                    ->whereNull('published_at')
                    ->delete();

                $locked = PreviouslyOnDraft::query()
                    ->whereBelongsTo($nextSession)
                    ->lockForUpdate()
                    ->first();

                if ($locked !== null && $locked->published_at !== null) {
                    return null;
                }

                return PreviouslyOnDraft::query()->updateOrCreate(
                    ['game_id' => $nextSession->id],
                    [
                        'campaign_id' => $campaign->id,
                        'body' => $body,
                    ],
                );
            });
        } catch (UniqueConstraintViolationException) {
            // Create-race (unique(game_id)): a concurrent compose (the
            // 24h reminder and a GM clicking Refresh) won the insert
            // between the lock miss and the create. Converge on the
            // winner's row — same outcome the loser would have written,
            // and the panel refresh path never surfaces a 500 for a lost
            // race (the LogicException catch is for programming errors,
            // not this). first(), not firstOrFail(): the winner's row can
            // itself be deleted before this re-read (a concurrent
            // supersedes or the observer's status-change sweep), and a
            // ModelNotFoundException would escape that catch — the null
            // guard below already handles a vanished winner.
            $draft = PreviouslyOnDraft::query()
                ->whereBelongsTo($nextSession)
                ->first();
        }

        // publish() won the race and the locked row stood down — the
        // published history is untouchable, exactly like the early
        // return above.
        if ($draft === null) {
            return null;
        }

        Log::info($existing === null ? 'previously_on.draft_composed' : 'previously_on.draft_refreshed', [
            'campaign_id' => $campaign->id,
            'game_id' => $nextSession->id,
            'draft_id' => $draft->id,
        ]);

        return $draft;
    }

    /**
     * The campaign's chronologically-NEXT scheduled session, or null —
     * the ONE definition of "next" (D230). The panel's draft preview and
     * every composer trigger resolve through here, so the two can never
     * disagree about which session a draft belongs to.
     */
    public function nextSession(Campaign $campaign): ?Game
    {
        /** @var Game|null $next */
        $next = $campaign->sessions()
            ->where('status', GameStatus::Scheduled->value)
            ->orderBy('date_time')
            ->first();

        return $next;
    }

    /**
     * Compose for the campaign's chronologically-NEXT scheduled session
     * (refresh-unpublished-or-compose), or null when none is scheduled.
     *
     * D230's invariant owner: a campaign carries at most one unpublished
     * draft, the forward-looking one for its next session — the same
     * session the Journal tab's panel resolves (CampaignPreviouslyOn::
     * latestDraft). Every automatic trigger that does not already have a
     * specific in-hand session MUST route through here: composing for a
     * newly created LATER session through upsertUnpublished() directly
     * would supersede (delete) the next session's draft and blank the
     * panel until the 24h reminder recomposed it — exactly the drift
     * this method exists to make impossible. Call sites with a specific
     * session already resolved (the observer's recompose, the panel's
     * refresh) keep calling upsertUnpublished() directly.
     */
    public function composeForNextSession(Campaign $campaign): ?PreviouslyOnDraft
    {
        $nextSession = $this->nextSession($campaign);

        if ($nextSession === null) {
            return null;
        }

        return $this->upsertUnpublished($campaign, $nextSession);
    }

    /**
     * Publish a reviewed draft: one GameBulletin via postBy, stamped in
     * the same transaction. NOT pinned — the completion sweep may expire
     * it once the session runs, which is correct for a pre-session digest.
     *
     * Delivery is deliberately absent here: GameBulletinObserver::created
     * is the single fan-out hook (board + in-app/mail/push/Discord per
     * prefs + Discord teasers). This method never adds a second path.
     *
     * The owner check is the caller's authorization (the GM surface gates
     * it); the status guard stays HERE so direct service misuse cannot
     * post to a canceled or completed session (GameBulletinPolicy::create
     * rules: Scheduled only).
     *
     * @throws \LogicException When the draft is already published
     *                         (translated, zero rows written) or its
     *                         session is not a publishable scheduled
     *                         campaign session.
     */
    public function publish(PreviouslyOnDraft $draft, User $publisher): GameBulletin
    {
        // Service-layer re-authorization (the CampaignJournalService
        // triple-layer idiom): the panel checks CampaignPolicy::
        // managePreviouslyOn before calling, and this guard resolves the
        // same ability — including the policy's global-admin before() —
        // so no caller can reach the bulletin write unvetted.
        if ($draft->campaign === null || ! $publisher->can('managePreviouslyOn', $draft->campaign)) {
            throw new \LogicException(__('journal.error_previously_on_not_available'));
        }

        return DB::transaction(function () use ($draft, $publisher): GameBulletin {
            // Re-fetch under lock so a concurrent publish cannot slip a
            // second bulletin through the same draft. first(), not
            // firstOrFail(): the row can be deleted between the panel's
            // resolution and this lock (the GameObserver status-leave
            // sweep when AutoCompleteGames finishes the session, or the
            // retarget supersedes-delete) — a ModelNotFoundException is
            // a RuntimeException and would escape the panel's
            // LogicException catch as a 500. Converge on the same flash
            // path instead (the upsertUnpublished race-precedent).
            $locked = PreviouslyOnDraft::query()
                ->whereKey($draft->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new \LogicException(__('journal.error_previously_on_not_available'));
            }

            if ($locked->published_at !== null) {
                throw new \LogicException(__('journal.error_draft_already_published'));
            }

            $game = $locked->game;
            $campaign = $locked->campaign;

            if ($game === null
                || $campaign === null
                || $game->campaign_id !== $campaign->id
                || $game->status !== GameStatus::Scheduled) {
                throw new \LogicException(__('journal.error_session_not_publishable'));
            }

            $bulletin = GameBulletin::postBy(
                gameId: (string) $game->id,
                userId: (string) $campaign->owner_id,
                content: $locked->body,
                expiresAt: $game->date_time?->copy()->addDays(7)->toDateTimeString(),
                isPinned: false,
            );

            $locked->update([
                'published_at' => now(),
                'published_bulletin_id' => $bulletin->id,
            ]);

            Log::info('previously_on.published', [
                'bulletin_id' => $bulletin->id,
                'campaign_id' => $campaign->id,
                'game_id' => $game->id,
                'draft_id' => $locked->id,
                'publisher_id' => $publisher->id,
            ]);

            return $bulletin;
        });
    }

    /**
     * The loose-threads heuristic: the latest condition-type and latest
     * item-type change per participant — the character's current open
     * state, not a firehose of history.
     *
     * Bounded by construction: DISTINCT ON resolves the per-(participant,
     * type) winner inside Postgres (the latestPerTask pattern), so the
     * fetch is at most two rows per participant regardless of campaign
     * age — the pre-fix shape loaded every state change ever written and
     * deduped in PHP. Column/literal references are code-controlled.
     *
     * @return Collection<int, CharacterStateChange>
     */
    private function latestConditionAndItemChanges(Campaign $campaign)
    {
        /** @var Collection<int, CharacterStateChange> $changes */
        $changes = $campaign->stateChanges()
            ->whereIn('change_type', [CharacterStateChangeType::Condition, CharacterStateChangeType::Item])
            ->whereNotNull('participant_id')
            // Literals are code-controlled (model + migration), same as
            // ScheduledTaskRun::latestPerTask's raw expression.
            ->selectRaw('distinct on (participant_id, change_type) character_state_changes.*')
            ->orderByRaw('participant_id, change_type, created_at desc, character_state_changes.id desc')
            ->with('participant.user')
            ->get();

        // DISTINCT ON pins the leading order terms to the partition keys,
        // so restore the render order here (newest first, id tiebreak —
        // same order the pre-fix query produced) on the bounded set.
        return $changes->sortBy([
            ['created_at', 'desc'],
            ['id', 'desc'],
        ])->values();
    }
}
