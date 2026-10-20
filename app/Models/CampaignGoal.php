<?php

namespace App\Models;

use App\Enums\GoalStatus;
use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\CampaignGoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A campaign goal (M071, docs/spec/campaign-journal-concept.md §2): one
 * system-agnostic board card — D&D quest, PbtA front, CoC mystery thread.
 *
 * The goal board groups by status and orders by sort. No numbers, no
 * sheets: title/description are free text. status_changed_at_game_id is
 * metadata — the session whose play last moved the status — read by the
 * S04 "Previously On" diff; it is nullOnDelete, never cascade (a deleted
 * session must not delete the goal).
 *
 * @property string $id
 * @property string $campaign_id
 * @property string $title
 * @property string|null $description
 * @property GoalStatus $status
 * @property string|null $status_changed_at_game_id
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CampaignGoal extends Model
{
    /** @use HasFactory<CampaignGoalFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'title',
        'description',
        'status',
        'status_changed_at_game_id',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'status' => GoalStatus::class,
            'sort' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * Owning campaign. Goals die with their campaign.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Session whose play last moved this goal's status (null when the
     * status changed outside a session or the session was deleted — the
     * stamp is metadata and never cascades).
     *
     * @return BelongsTo<Game, $this>
     */
    public function statusChangedAtGame(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'status_changed_at_game_id');
    }
}
