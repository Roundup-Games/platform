<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use App\Traits\HasTranslations;
use App\Traits\StringMorphMediaKey;

class Team extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use StringMorphMediaKey { StringMorphMediaKey::media insteadof InteractsWithMedia; }
    use HasTranslations;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $translatable = ['description'];

    protected $fillable = [
        'id',
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
            if (empty($team->id)) {
                $team->id = (string) Str::orderedUuid();
            }
            if (empty($team->slug)) {
                $team->slug = Str::slug($team->name) . '-' . Str::random(6);
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);

        $this->addMediaConversion('medium')
            ->width(400)
            ->height(400)
            ->sharpen(8);

        $this->addMediaConversion('large')
            ->width(800)
            ->height(800)
            ->sharpen(5);
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
