<?php

namespace App\Models;

use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $role
 * @property string|null $status
 * @property string|null $jersey_number
 * @property string|null $position
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TeamMember extends Pivot
{
    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    protected $table = 'team_members';

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'team_id', 'user_id', 'role', 'status', 'jersey_number',
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

    protected static function booted(): void
    {
        static::creating(function (self $member) {
            if (empty($member->id)) {
                $member->id = (string) Str::orderedUuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
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
