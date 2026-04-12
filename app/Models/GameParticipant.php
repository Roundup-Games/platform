<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GameParticipant extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['game_id', 'user_id', 'role', 'status'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            if (empty($participant->id)) {
                $participant->id = (string) Str::uuid();
            }
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
