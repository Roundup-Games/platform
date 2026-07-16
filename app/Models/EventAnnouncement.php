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

    /**
     * Per-announcement visibility levels (set by organizers via the admin form).
     *
     * @see self::scopeVisibleTo()
     */
    public const VISIBILITY_ALL = 'all';

    public const VISIBILITY_REGISTERED = 'registered';

    public const VISIBILITY_PRIVATE = 'private';

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

    /**
     * Scope to announcements visible to the given viewer within an event.
     *
     * Honors the per-announcement visibility column organizers set via the
     * admin form (AnnouncementsRelationManager). Compose with
     * {@see scopePublished()} on public read paths:
     *
     *  - VISIBILITY_ALL        always shown (even to anonymous visitors);
     *  - VISIBILITY_REGISTERED only to users with an active (non-cancelled)
     *                          registration for the event;
     *  - VISIBILITY_PRIVATE    only to viewers who can manage the event
     *                          (organizer, Event Admin, or global admin).
     *
     * Visibility is context-dependent on the owning event, so the event is a
     * required parameter rather than inferred from the announcement row.
     *
     * @param  Builder<static>  $query
     * @param  Event  $event  Owning event — used to test registration / manager status.
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer, Event $event): void
    {
        $levels = [self::VISIBILITY_ALL];

        if ($viewer !== null) {
            $canManage = $viewer->can('update', $event);

            // Registered-level content is visible to anyone with an active
            // registration, and to organizers/admins (who manage everything
            // on their own event even without a registration row).
            if ($canManage || $event->registrations()->whereBelongsTo($viewer)->whereNull('cancelled_at')->exists()) {
                $levels[] = self::VISIBILITY_REGISTERED;
            }

            if ($canManage) {
                $levels[] = self::VISIBILITY_PRIVATE;
            }
        }

        $query->whereIn('visibility', $levels);
    }
}
