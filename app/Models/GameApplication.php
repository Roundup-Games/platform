<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameApplication extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['game_id', 'user_id', 'status', 'message'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
