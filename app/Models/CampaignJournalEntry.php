<?php

namespace App\Models;

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\CampaignJournalEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A campaign journal entry (M071, docs/spec/campaign-journal-concept.md §2):
 * one append-only row per recap, in-character post or GM prep note.
 *
 * The journal keys off campaign_id only — every campaign type gets one
 * (D218). kind=recap entries for a session are the source of truth behind
 * the games.recap projection (D220), synced transactionally by
 * CampaignJournalService; at most one recap per session is enforced by the
 * campaign_journal_entries_one_recap_per_session partial unique index.
 *
 * @property string $id
 * @property string $campaign_id
 * @property string|null $game_id
 * @property string $author_user_id
 * @property string|null $author_participant_id
 * @property JournalEntryKind $kind
 * @property string $body
 * @property JournalEntryVisibility $visibility
 * @property string|null $in_game_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CampaignJournalEntry extends Model
{
    /** @use HasFactory<CampaignJournalEntryFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'game_id',
        'author_user_id',
        'author_participant_id',
        'kind',
        'body',
        'visibility',
        'in_game_date',
    ];

    protected function casts(): array
    {
        return [
            'kind' => JournalEntryKind::class,
            'visibility' => JournalEntryVisibility::class,
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * Owning campaign. Entries die with their campaign.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Session the entry belongs to (null for interlude entries, spec §2).
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Authoring user — durable attribution that survives participant
     * removal (author_participant_id is nullOnDelete, never cascade).
     *
     * @return BelongsTo<User, $this>
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Authoring participant row (character link, nullable).
     *
     * @return BelongsTo<CampaignParticipant, $this>
     */
    public function authorParticipant(): BelongsTo
    {
        return $this->belongsTo(CampaignParticipant::class, 'author_participant_id');
    }
}
