<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistration extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'event_id', 'team_id', 'user_id', 'registration_type', 'division',
        'status', 'payment_status', 'payment_id', 'roster', 'notes',
        'internal_notes', 'confirmed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'roster' => 'array',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
