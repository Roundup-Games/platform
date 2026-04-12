<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Team extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    protected $fillable = [
        'name', 'slug', 'description', 'city', 'country', 'logo_url',
        'primary_color', 'secondary_color', 'founded_year', 'website',
        'social_links', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $team) {
            if (empty($team->slug)) {
                $team->slug = Str::slug($team->name);
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
    }

    // ── Relationships ──────────────────────────────────

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function activeMembers()
    {
        return $this->hasMany(TeamMember::class)->where('status', 'active');
    }

    public function captains()
    {
        return $this->hasMany(TeamMember::class)
            ->where('role', 'captain')
            ->where('status', 'active');
    }

    public function eventRegistrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    // ── Helpers ────────────────────────────────────────

    public function isCaptain(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->where('status', 'active')
            ->exists();
    }

    public function hasMember(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
