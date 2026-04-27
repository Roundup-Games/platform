<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAppVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'visit_date',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
        ];
    }

    // ── Relationships ──────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSinceDate(Builder $query, string $date): Builder
    {
        return $query->where('visit_date', '>=', $date);
    }
}
