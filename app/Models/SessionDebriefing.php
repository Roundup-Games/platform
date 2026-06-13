<?php

namespace App\Models;

use App\Enums\DebriefingToolType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property array<string, mixed> $responses
 */
class SessionDebriefing extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'game_id',
        'user_id',
        'tool_type',
        'responses',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'tool_type' => DebriefingToolType::class,
            'responses' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $debriefing) {
            if (empty($debriefing->id)) {
                $debriefing->id = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByToolType(Builder $query, string $toolType)
    {
        return $query->where('tool_type', $toolType);
    }
}
