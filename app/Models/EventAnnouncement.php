<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAnnouncement extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'event_id', 'author_id', 'title', 'content',
        'is_pinned', 'is_published', 'visibility',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }
}
