<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class EventAnnouncement extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['title', 'content'];

    protected static function booted(): void
    {
        static::creating(function (self $announcement) {
            if (empty($announcement->id)) {
                $announcement->id = (string) Str::uuid();
            }
        });
    }

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

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query)
    {
        return $query->where('is_published', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePinned(Builder $query)
    {
        return $query->where('is_pinned', true);
    }
}
