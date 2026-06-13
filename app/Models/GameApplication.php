<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

class GameApplication extends Pivot
{
    protected $table = 'game_applications';

    protected $keyType = 'string';

    protected $fillable = ['game_id', 'user_id', 'status', 'message'];

    protected static function booted(): void
    {
        static::creating(function (self $application) {
            if (empty($application->id)) {
                $application->id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
