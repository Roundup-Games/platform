<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\CharterTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saved charter template (M070/S04, R085): a reusable charter snapshot a
 * member GM saves from one of their charters and seeds new charters from
 * ("start from a saved template"). Convenience data, not history — rows
 * are deleted and re-saved, never versioned.
 *
 * @property array<string, mixed> $content cast attribute (jsonb) — the
 *                                         schema-aware property extension types it string without this
 */
class CharterTemplate extends Model
{
    /** @use HasFactory<CharterTemplateFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $table = 'charter_templates';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'name',
        'content',
        'content_schema',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'content_schema' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * The GM who saved the template (owner of every gate).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
