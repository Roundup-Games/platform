<?php

namespace App\Services;

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * Write path for the recruitment summary (M071/S05,
 * docs/spec/campaign-journal-concept.md §4, R082): the spoiler-safe
 * "Campaign so far" block a GM publishes to the public campaign page.
 *
 * Storage contract (the campaigns migration): three dedicated nullable
 * columns — never markers inside the translatable description — so
 * removal is a column clear and staleness simple arithmetic. The block
 * is its own, GM-gated surface: this service NEVER writes
 * campaign_journal_entries and NEVER writes the description or any
 * translatable column (the journal is never auto-public, spec §4).
 *
 * Default-safe assembly (spec §4.1/§4.2): the auto-draft draws ONLY
 * from explicit-safe sources — the sessions-played count and the
 * charter's overview (pitch, tone axes, themes). Goal titles enter only
 * when the GM marks them safe and recap excerpts only when the GM picks
 * them (the T3 composer); nothing spoiler-y is included by default.
 *
 * Guard idiom follows CampaignJournalService: translated journal.error_*
 * LogicExceptions BEFORE any write; single UPDATE inside a transaction;
 * structured Log::info on every mutation.
 */
class CampaignRecruitmentSummaryService
{
    /** Spec §4.1: the auto-drafted pitch is clipped to a readable teaser. */
    private const int PITCH_CLIP = 500;

    /** Same ceiling as the journal body columns (spec §2). */
    private const int DRAFT_CEILING = 10000;

    /** Spec §4.1: staleness nudge after 3 new sessions without refresh. */
    private const int STALE_THRESHOLD = 3;

    /** Spec §4.1 spoiler-safety checklist — all three must be confirmed. */
    private const array CHECKLIST_ITEMS = ['review_excerpts', 'names_removed', 'visitor_preview'];

    /** Charter overview tone axes (CharterContent::MATERIAL_OVERVIEW_FIELDS). */
    private const array TONE_AXES = ['serious_lighthearted', 'gritty_heroic', 'political_dungeon'];

    /**
     * Plain-text auto-draft assembled only from explicit-safe sources, in
     * the caller's current locale: a sessions-played line; then, when a
     * charter exists, a clipped pitch paragraph and a tone/themes line
     * (the latter only when the charter carries themes).
     *
     * Goal titles and recap bodies NEVER enter the default draft — they
     * are GM-marked/GM-picked in the composer (spec §4.1).
     */
    public function draft(Campaign $campaign): string
    {
        $lines = [
            __('journal.content_recruitment_sessions_played', [
                'count' => $campaign->completedSessionsCount(),
            ]),
        ];

        $charter = $campaign->charter()->first();

        if ($charter !== null) {
            $content = CharterContent::fromArray($charter->content ?? [])
                ->toArray();

            // toArray()'s overview is a normalized array<string, mixed>;
            // narrow each read so level-9 analysis never sees mixed.
            $overview = $content['overview'] ?? null;
            $overview = is_array($overview) ? $overview : [];

            $pitch = $overview['pitch'] ?? null;
            $pitch = is_string($pitch) ? trim($pitch) : '';

            if ($pitch !== '') {
                $lines[] = Str::limit($pitch, self::PITCH_CLIP);
            }

            $themes = $overview['themes'] ?? null;
            $themes = is_array($themes) ? $themes : [];

            if ($themes !== []) {
                $lines[] = __('journal.content_recruitment_tone_themes', [
                    'themes' => implode(', ', $this->toneAndThemeLabels($overview)),
                ]);
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * Publish the GM-edited draft: stamp all three columns in one
     * transactional UPDATE and log it.
     *
     * @param  array<string, mixed>  $checklist  the spec §4.1 spoiler-safety
     *                                           checklist — every item true
     *
     * @throws LogicException When the author is not the campaign owner,
     *                        any checklist item is unconfirmed, the draft
     *                        is empty, or it exceeds 10000 characters —
     *                        always BEFORE any write.
     */
    public function apply(Campaign $campaign, User $user, string $draft, array $checklist): void
    {
        if ((string) $campaign->owner_id !== (string) $user->id) {
            throw new LogicException(__('journal.error_recruitment_unauthorized'));
        }

        foreach (self::CHECKLIST_ITEMS as $item) {
            if (! (bool) ($checklist[$item] ?? false)) {
                throw new LogicException(__('journal.error_recruitment_checklist_incomplete'));
            }
        }

        if (trim($draft) === '') {
            throw new LogicException(__('journal.error_recruitment_draft_required'));
        }

        if (mb_strlen($draft) > self::DRAFT_CEILING) {
            throw new LogicException(__('journal.error_recruitment_draft_too_long'));
        }

        $completedSessions = $campaign->completedSessionsCount();

        DB::transaction(function () use ($campaign, $draft, $completedSessions): void {
            $campaign->update([
                'recruitment_summary' => $draft,
                'recruitment_summary_completed_sessions' => $completedSessions,
                'recruitment_summary_refreshed_at' => now(),
            ]);
        });

        Log::info('recruitment_summary.applied', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'completed_sessions' => $completedSessions,
            'content_length' => mb_strlen($draft),
        ]);
    }

    /**
     * Remove the block: clear all three columns. Fully reversible both
     * ways — this NEVER touches journal rows or the description.
     *
     * @throws LogicException When the author is not the campaign owner.
     */
    public function remove(Campaign $campaign, User $user): void
    {
        if ((string) $campaign->owner_id !== (string) $user->id) {
            throw new LogicException(__('journal.error_recruitment_unauthorized'));
        }

        DB::transaction(function () use ($campaign): void {
            $campaign->update([
                'recruitment_summary' => null,
                'recruitment_summary_completed_sessions' => null,
                'recruitment_summary_refreshed_at' => null,
            ]);
        });

        Log::info('recruitment_summary.removed', [
            'campaign_id' => $campaign->id,
        ]);
    }

    /**
     * Spec §4.1 staleness nudge: true once >= 3 completed sessions have
     * accrued since the summary was last stamped.
     */
    public function isStale(Campaign $campaign): bool
    {
        return $campaign->recruitmentSummaryStaleSessions() >= self::STALE_THRESHOLD;
    }

    /**
     * The tone/theme chips for the draft's tone-themes line: non-middle
     * tone-axis labels (<= 2 low pole, >= 4 high pole — a centered dial
     * says nothing) followed by the theme chips, all rendered through the
     * EXISTING charter.* lang keys so the vocabularies can never drift
     * (M070 canon; never duplicated here). fromArray() has already
     * normalized the axes to 1..5 ints and dropped unknown themes.
     *
     * @param  array<string, mixed>  $overview  normalized charter overview section
     * @return list<string>
     */
    private function toneAndThemeLabels(array $overview): array
    {
        $labels = [];

        foreach (self::TONE_AXES as $axis) {
            $dial = $overview['tone_'.$axis] ?? null;

            if (is_numeric($dial)) {
                $dial = (int) $dial;

                if ($dial <= 2) {
                    $labels[] = __('charter.field_overview_tone_'.$axis.'_low');
                } elseif ($dial >= 4) {
                    $labels[] = __('charter.field_overview_tone_'.$axis.'_high');
                }
            }
        }

        $themes = is_array($overview['themes'] ?? null) ? $overview['themes'] : [];

        foreach ($themes as $theme) {
            if (is_string($theme) && $theme !== '') {
                $labels[] = __('charter.label_theme_'.$theme);
            }
        }

        return $labels;
    }
}
