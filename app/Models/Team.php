<?php

namespace App\Models;

use App\Relations\StringKeyMorphMany;
use App\Traits\StringMorphMediaKey;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\SchemaOrg\Organization as SchemaOrganization;
use Spatie\SchemaOrg\PostalAddress;
use Spatie\Translatable\HasTranslations;

/**
 * @property-read Collection<int, TeamMember> $activeMembers
 *  @property-read int $active_members_count
 * @property TeamMember|null $pivot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, string|null>|null $social_links
 */
class Team extends Model implements HasMedia
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use HasSEO;
    use HasTranslations;
    use InteractsWithMedia;
    use StringMorphMediaKey { StringMorphMediaKey::media insteadof InteractsWithMedia; }

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<int, string> */
    public array $translatable = ['description'];

    protected $fillable = [
        'id',
        'name', 'slug', 'description', 'city', 'country', 'logo_url',
        'primary_color', 'secondary_color', 'founded_year', 'website',
        'social_links', 'is_active', 'created_by', 'language',
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
                $team->slug = Str::slug($team->name).'-'.Str::random(6);
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TeamMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    /**
     * @return HasMany<TeamMember, $this>
     */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class)->where('status', 'active');
    }

    /**
     * @return HasMany<TeamMember, $this>
     */
    public function captains()
    {
        return $this->hasMany(TeamMember::class)
            ->where('role', 'captain')
            ->where('status', 'active');
    }

    /** @return HasMany<EventRegistration, $this> */
    public function eventRegistrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    // ── Short Links ────────────────────────────────────

    /**
     * @return Builder<ShortLink>
     */
    public function shortLinks()
    {
        return (new StringKeyMorphMany(
            $this->newRelatedInstance(ShortLink::class)->newQuery(),
            $this,
            'linkable_type',
            'linkable_id',
            'id'
        ))->getQuery()->where('linkable_type', $this->getMorphClass());
    }

    // ── Helpers ────────────────────────────────────────

    public function isCaptain(User $user): bool
    {
        return $this->members()
            ->whereBelongsTo($user)
            ->where('role', 'captain')
            ->where('status', 'active')
            ->exists();
    }

    public function hasMember(User $user): bool
    {
        return $this->members()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->exists();
    }

    // ── SEO ────────────────────────────────────────────

    public function getDynamicSEOData(): SEOData
    {
        $description = $this->description
            ? Str::limit(strip_tags($this->description), 160)
            : trim("{$this->name}".($this->city ? " — {$this->city}" : '').($this->country ? ", {$this->country}" : ''));

        $image = $this->getFirstMediaUrl('logo', 'large') ?: asset('images/og-default.jpg');

        $robots = $this->is_active
            ? 'index, follow'
            : 'noindex, nofollow';

        $schema = null;

        // Only generate Organization schema for active teams
        if ($this->is_active) {
            $schema = SchemaCollection::initialize();

            $org = (new SchemaOrganization)
                ->name($this->name)
                ->description(Str::limit(strip_tags((string) $this->description), 500) ?: '')
                ->url(route('teams.detail', $this->slug));

            // Logo
            $logoUrl = $this->getFirstMediaUrl('logo', 'large');
            if ($logoUrl) {
                $org->logo($logoUrl);
            }

            // Address
            if ($this->city || $this->country) {
                $address = (new PostalAddress);
                if ($this->city) {
                    $address->addressLocality($this->city);
                }
                if ($this->country) {
                    $address->addressCountry($this->country);
                }
                $org->address($address);
            }

            // Founding date
            if ($this->founded_year) {
                $org->foundingDate((string) $this->founded_year);
            }

            // SameAs social links
            $sameAs = [];
            if ($this->website) {
                $sameAs[] = $this->website;
            }
            if (! empty($this->social_links)) {
                foreach ($this->social_links as $link) {
                    if (is_string($link) && filter_var($link, FILTER_VALIDATE_URL)) {
                        $sameAs[] = $link;
                    }
                }
            }
            if (! empty($sameAs)) {
                $org->sameAs($sameAs);
            }

            $schema->push($org->toArray());
        }

        return new SEOData(
            title: $this->name,
            description: $description ?: null,
            image: $image,
            robots: $robots,
            schema: $schema,
        );
    }
}
