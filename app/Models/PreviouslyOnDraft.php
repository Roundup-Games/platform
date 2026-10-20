<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\PreviouslyOnDraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Previously On draft (M071/S04, docs/spec/campaign-journal-concept.md
 * §3, D221): the auto-composed pre-session digest awaiting the member
 * GM's one-click publish.
 *
 * A dedicated entity, never a GameBulletin row: bulletin creation fires
 * GameBulletinObserver::created — the single fan-out hook for the whole
 * D185 channel matrix — so drafts live here until publish creates exactly
 * one bulletin. One draft per target session (unique game_id): a compose
 * or 24h-reminder refresh UPDATES the unpublished row in place; rows with
 * published_at set are immutable — the composer never touches them.
 *
 * @property string $id
 * @property string $campaign_id
 * @property string $game_id
 * @property string $body
 * @property Carbon|null $published_at
 * @property string|null $published_bulletin_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PreviouslyOnDraft extends Model
{
    /** @use HasFactory<PreviouslyOnDraftFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'game_id',
        'body',
        'published_at',
        'published_bulletin_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * Owning campaign. Drafts die with their campaign.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Target (upcoming) session the digest is published to. Cascade: a
     * canceled or deleted session takes its draft with it.
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * The bulletin the publish created (null while unpublished, and after
     * the bulletin's deletion — nullOnDelete keeps the audit trail).
     *
     * @return BelongsTo<GameBulletin, $this>
     */
    public function publishedBulletin(): BelongsTo
    {
        return $this->belongsTo(GameBulletin::class, 'published_bulletin_id');
    }
}
