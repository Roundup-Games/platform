<?php

namespace App\Models;

use App\Enums\CharacterStateChangeType;
use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\CharacterStateChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A character state change (M071, docs/spec/campaign-journal-concept.md
 * §2): one system-agnostic timeline row behind the per-character state
 * timelines — "Ti reached level 3", "lost the amulet", "cursed".
 *
 * No numbers, no sheets, no system coupling: delta is free text. The
 * participant link is nullable and nullOnDelete (journal
 * author_participant_id precedent) — the campaign's state history
 * survives participant removal. game_id is nullable for interlude state
 * notes (mirroring journal entries) but cascades: a deleted session
 * takes its state changes with it, matching recap-on-delete.
 *
 * @property string $id
 * @property string $campaign_id
 * @property string|null $participant_id
 * @property string|null $game_id
 * @property CharacterStateChangeType $change_type
 * @property string $delta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CharacterStateChange extends Model
{
    /** @use HasFactory<CharacterStateChangeFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'participant_id',
        'game_id',
        'change_type',
        'delta',
    ];

    protected function casts(): array
    {
        return [
            'change_type' => CharacterStateChangeType::class,
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * Owning campaign. State changes die with their campaign.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The character the state change belongs to (nullable): campaign
     * history survives participant removal (nullOnDelete, never cascade).
     *
     * @return BelongsTo<CampaignParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(CampaignParticipant::class);
    }

    /**
     * Session the state change belongs to (null for interlude state
     * notes, spec §2). Cascade: a deleted session takes its state
     * changes with it, matching recap-on-delete.
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
