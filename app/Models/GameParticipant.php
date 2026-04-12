<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameParticipant extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['game_id', 'user_id', 'role', 'status'];

    public $timestamps = false;

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
