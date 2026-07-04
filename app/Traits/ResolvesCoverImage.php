<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Cover-image media behavior for host-uploaded entity covers.
 *
 * Bundles the three pieces every cover surface (hero/card/OG/tile/feed) shares:
 *
 *  - registerMediaCollections(): a single-file 'cover' collection accepting
 *    jpeg/png/webp (mirrors GameSystem::registerMediaCollections()).
 *  - registerMediaConversions(): a 'thumb' (150x150, sharpen 10) matching
 *    GameSystem, plus an 'og' (1200x630, Fit::Crop) for social-share cards.
 *  - resolveCoverUrl(): the deterministic fallback chain every image consumer
 *    should call instead of hand-rolling a representative-system lookup:
 *      1. host-uploaded cover media (verified present on disk)
 *      2. the entity's first offered GameSystem cover (representative)
 *      3. the bundled images/og-default.jpg static asset
 *
 * Apply alongside Spatie\MediaLibrary\InteractsWithMedia (and the project's
 * StringMorphMediaKey, since the media.model_id column is varchar(36)) on any
 * model that also exposes a gameSystems() BelongsToMany relation — currently
 * Game and Campaign.
 *
 * The on-disk file_exists check in resolveCoverUrl() mirrors
 * GameSystem::coverImageUrl(): a media row whose source file was removed
 * (e.g. by a moderation takedown that deletes the file) falls through cleanly
 * to the representative/default rung instead of rendering a broken <img>.
 */
trait ResolvesCoverImage
{
    /**
     * Register the single-file cover collection.
     *
     * singleFile() means a new upload replaces the prior cover (one cover per
     * entity). The mime allow-list rejects anything that isn't a web-safe
     * raster image; Livewire/Create flows additionally enforce max size.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Register shared cover conversions.
     *
     * 'thumb' mirrors GameSystem (150x150, sharpen 10) for card/listing use.
     * 'og' produces a 1200x630 social-share card via Fit::Crop so OG/Twitter
     * cards fill their canonical frame regardless of the uploaded image's
     * aspect ratio. Conversions run on the default queue (same as GameSystem).
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);

        $this->addMediaConversion('og')
            ->fit(Fit::Crop, 1200, 630);
    }

    /**
     * Resolve this entity's cover image URL via the deterministic fallback chain.
     *
     * Order:
     *  1. Host-uploaded cover media — returned only when the file actually
     *     exists on disk (defends against a moderation takedown or a stale
     *     media row whose source file was deleted). Mirrors
     *     GameSystem::coverImageUrl().
     *  2. The first offered GameSystem's cover (the representative image for a
     *     session/campaign that didn't upload its own). Reads the gameSystems
     *     BelongsToMany relation (S06) — callers should eager-load it
     *     (->with('gameSystems')) on listing/card pages to avoid an N+1 here.
     *  3. The bundled images/og-default.jpg static asset — always resolved,
     *     so this method never returns null in practice (the nullable return
     *     type keeps the representative-system delegation honest).
     *
     * @param  string  $conversion  Optional Spatie conversion name (e.g. 'thumb',
     *                              'og') applied to both the host cover and the representative system
     *                              cover. Empty string resolves the original uploaded file.
     */
    public function resolveCoverUrl(string $conversion = ''): ?string
    {
        // 1. Host-uploaded cover (verified on disk).
        $media = $this->getFirstMedia('cover');

        if ($media instanceof Media) {
            $path = $conversion !== ''
                ? $media->getPath($conversion)
                : $media->getPath();

            if ($path !== '' && file_exists($path)) {
                return $conversion !== ''
                    ? $media->getUrl($conversion)
                    : $media->getUrl();
            }

            // Structured log when a media row exists but its file is missing —
            // surfaces moderation takedowns and storage drift in production.
            // Values are stored uncast; the logger accepts mixed context.
            Log::warning('cover.media_file_missing', [
                'entity_type' => static::class,
                'entity_id' => $this->getKey(),
                'media_id' => $media->getKey(),
                'collection' => 'cover',
                'conversion' => $conversion,
                'rung' => 'host_cover',
            ]);
        }

        // 2. Representative GameSystem cover — the anchor system for a
        //    multi-system Gathering, or the campaign's recurring default.
        $representative = $this->gameSystems->first();

        if ($representative !== null) {
            $fallback = $representative->coverImageUrl($conversion);

            if (is_string($fallback) && $fallback !== '') {
                return $fallback;
            }
        }

        // 3. Bundled static default. asset() always resolves to a non-empty URL.
        return asset('images/og-default.jpg');
    }

    /**
     * Whether this entity currently has a host-uploaded cover in the 'cover'
     * collection (rung 1 of resolveCoverUrl()).
     *
     * Used by the admin moderation surface to gate the "Clear Cover Image"
     * action — it only shows when there's actually a host cover to clear, so
     * reviewers never see a no-op button on entities using the representative
     * or default fallback rungs.
     */
    public function hasCover(): bool
    {
        return $this->getFirstMedia('cover') instanceof Media;
    }

    /**
     * Clear the host-uploaded cover image (moderation takedown).
     *
     * Removes every media item in the 'cover' collection (singleFile() means
     * there's at most one) and emits a structured log so takedowns are
     * auditable. After this, resolveCoverUrl() falls through to the
     * representative system cover or the default asset — its on-disk
     * file_exists guard means a stale media row whose file was deleted also
     * falls through cleanly, but clearing the row outright is the canonical
     * path so the entity stops advertising any host cover.
     *
     * @return bool True when a cover was actually cleared, false when there
     *              was no host cover to remove (no-op).
     */
    public function clearCoverImage(): bool
    {
        if (! $this->hasCover()) {
            return false;
        }

        // Capture count before clearing for the audit log (clearMediaCollection
        // itself returns void).
        $count = $this->getMedia('cover')->count();

        $this->clearMediaCollection('cover');

        Log::info('cover.cleared', [
            'entity_type' => static::class,
            'entity_id' => $this->getKey(),
            'collection' => 'cover',
            'media_removed' => $count,
            'rung' => 'host_cover',
        ]);

        return true;
    }
}
