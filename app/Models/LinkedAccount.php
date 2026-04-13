<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedAccount extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'token',
        'refresh_token',
        'token_expires_at',
        'provider_meta',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'provider_meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
