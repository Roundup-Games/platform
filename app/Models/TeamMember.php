<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    protected $fillable = [
        'team_id', 'user_id', 'role', 'status', 'jersey_number',
        'position', 'joined_at', 'left_at', 'invited_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public $timestamps = false;

    // ── Relationships ──────────────────────────────────

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // ── Helpers ────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCaptain(): bool
    {
        return $this->role === 'captain';
    }
}
