<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Event extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'slug', 'description', 'short_description', 'type', 'status',
        'venue_name', 'venue_address', 'city', 'country', 'postal_code',
        'start_date', 'end_date', 'registration_opens_at', 'registration_closes_at',
        'registration_type', 'max_teams', 'max_participants',
        'min_players_per_team', 'max_players_per_team',
        'team_registration_fee', 'individual_registration_fee',
        'early_bird_discount', 'early_bird_deadline',
        'organizer_id', 'contact_email', 'contact_phone',
        'rules', 'schedule', 'divisions', 'amenities', 'requirements',
        'is_public', 'is_featured', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'early_bird_deadline' => 'datetime',
            'team_registration_fee' => 'integer',
            'individual_registration_fee' => 'integer',
            'early_bird_discount' => 'integer',
            'min_players_per_team' => 'integer',
            'max_players_per_team' => 'integer',
            'max_teams' => 'integer',
            'max_participants' => 'integer',
            'rules' => 'array',
            'schedule' => 'array',
            'divisions' => 'array',
            'amenities' => 'array',
            'requirements' => 'array',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            if (empty($event->id)) {
                $event->id = (string) Str::uuid();
            }
            if (empty($event->slug)) {
                $event->slug = Str::slug($event->name) . '-' . Str::random(6);
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('banner')->singleFile();
    }

    // ── Relationships ──────────────────────────────────

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(EventAnnouncement::class);
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeRegistrationOpen($query)
    {
        return $query->where('status', 'registration_open');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>=', now())->orderBy('start_date');
    }

    // ── Helpers ────────────────────────────────────────

    public function isRegistrationOpen(): bool
    {
        if ($this->status !== 'registration_open') {
            return false;
        }

        if ($this->registration_opens_at && now()->lt($this->registration_opens_at)) {
            return false;
        }

        if ($this->registration_closes_at && now()->gt($this->registration_closes_at)) {
            return false;
        }

        return true;
    }

    public function hasCapacity(): bool
    {
        if ($this->registration_type === 'team' || $this->registration_type === 'both') {
            if ($this->max_teams && $this->registrations()->where('registration_type', 'team')->count() >= $this->max_teams) {
                return false;
            }
        }

        if ($this->registration_type === 'individual' || $this->registration_type === 'both') {
            if ($this->max_participants && $this->registrations()->where('registration_type', 'individual')->count() >= $this->max_participants) {
                return false;
            }
        }

        return true;
    }
}
