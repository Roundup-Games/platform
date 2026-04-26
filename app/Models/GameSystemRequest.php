<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameSystemRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'bgg_url',
        'publisher',
        'designer',
        'notes',
        'status',
        'game_system_id',
        'reviewed_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'status' => 'string',
        ];
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopePending($query)
    {
        $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        $query->where('status', 'rejected');
    }

    public function scopeDuplicate($query)
    {
        $query->where('status', 'duplicate');
    }

    // ── Relationships ──────────────────────────────────

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function gameSystem(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class);
    }
}
