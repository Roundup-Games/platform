<?php

namespace App\Services\StartPlaying;

/**
 * Parse StartPlaying.games Apollo cache data into structured arrays.
 *
 * All cache data arrives as array<string, mixed> from JSON. The typed accessor
 * helpers (arr, items, str) ensure PHPStan level 9 compliance by narrowing
 * mixed values at each access point.
 */
class SpParser
{
    /**
     * Read an array value from a mixed-offset source, defaulting to empty array.
     *
     * @param  array<string|int, mixed>  $source
     * @return array<string|int, mixed>
     */
    private function arr(array $source, string|int $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Ensure a mixed value is iterable, returning as array or empty array.
     *
     * @return array<int|string, mixed>
     */
    private function items(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Safely cast a mixed value to string, with a default fallback.
     */
    private function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Parse a game system detail page from the Apollo cache.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    public function parseSystem(array $cache, string $slug): ?array
    {
        $seoPage = $this->findSeoPage($cache, $slug);
        if (! $seoPage) {
            return null;
        }

        $heroSection = $this->arr($seoPage, 'heroSection');
        $rawMetadata = $this->arr($heroSection, 'metadata');
        $metadata = $this->parseHeroMetadata(array_values($rawMetadata));

        // Resolve publisher from metadata
        $publisher = null;
        $publisherUrl = null;
        $publisherItems = $this->items($metadata['Publisher'] ?? null);
        if (! empty($publisherItems)) {
            $first = is_array($publisherItems[0] ?? null) ? $publisherItems[0] : [];
            $publisher = $this->str($first['text'] ?? null);
            $publisherUrl = is_string($first['url'] ?? null) ? $first['url'] : null;
        }

        // Resolve release date
        $releaseDate = null;
        $releaseDateItems = $this->items($metadata['Release Date'] ?? null);
        if (! empty($releaseDateItems)) {
            $first = is_array($releaseDateItems[0] ?? null) ? $releaseDateItems[0] : [];
            $releaseDate = $this->str($first['text'] ?? null);
        }

        // Resolve player range from Details
        $playerRange = null;
        $mechanic = null;
        $detailsItems = $this->items($metadata['Details'] ?? null);
        foreach ($detailsItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = is_string($item['text'] ?? null) ? $item['text'] : '';
            if (preg_match('/\d+.*(?:Player|player)/i', $text)) {
                $playerRange = $text;
            }
            if (! preg_match('/Player/i', $text)) {
                $mechanic = $text;
            }
        }

        // Resolve genres/Themes
        $genres = [];
        $themeItems = $this->items($metadata['Themes'] ?? null);
        foreach ($themeItems as $item) {
            if (is_array($item) && is_string($item['text'] ?? null)) {
                $genres[] = $item['text'];
            }
        }

        // Resolve FAQs
        $faqs = [];
        foreach ($this->arr($seoPage, 'faqs') as $faqRef) {
            $ref = is_array($faqRef) ? $this->str($faqRef['__ref'] ?? null) : '';
            $faq = $this->resolveRef($cache, $ref);
            if ($faq) {
                $faqs[] = [
                    'questionText' => $this->str($faq['questionText'] ?? null),
                    'answerText' => $this->str($faq['answerText'] ?? null),
                ];
            }
        }

        // Resolve external links
        $externalLinks = [];
        foreach ($this->arr($seoPage, 'externalLinks') as $linkRef) {
            $ref = is_array($linkRef) ? $this->str($linkRef['__ref'] ?? null) : '';
            $link = $this->resolveRef($cache, $ref);
            if ($link) {
                $externalLinks[] = [
                    'title' => $this->str($link['title'] ?? null),
                    'url' => $this->str($link['url'] ?? null),
                    'type' => $this->str($link['type'] ?? null),
                    'image' => $link['image'] ?? null,
                    'description' => $link['description'] ?? null,
                ];
            }
        }

        // Resolve showcases
        $showcases = [];
        foreach ($this->arr($seoPage, 'showcases') as $showcaseRef) {
            $ref = is_array($showcaseRef) ? $this->str($showcaseRef['__ref'] ?? null) : '';
            $showcase = $this->resolveRef($cache, $ref);
            if ($showcase) {
                $items = [];
                foreach ($this->arr($showcase, 'items') as $itemRef) {
                    $itemRefStr = is_array($itemRef) ? $this->str($itemRef['__ref'] ?? null) : '';
                    $item = $this->resolveRef($cache, $itemRefStr);
                    if ($item) {
                        $items[] = [
                            'title' => $this->str($item['title'] ?? null),
                            'description' => $this->str($item['description'] ?? null),
                            'image' => $item['image'] ?? null,
                        ];
                    }
                }
                $showcases[] = [
                    'title' => $this->str($showcase['title'] ?? null),
                    'items' => $items,
                ];
            }
        }

        // Resolve instructions
        $instructions = null;
        $instructionRefs = $this->arr($seoPage, 'instructions');
        if (! empty($instructionRefs)) {
            $firstRef = is_array($instructionRefs[0] ?? null) ? $instructionRefs[0] : [];
            $ref = $this->str($firstRef['__ref'] ?? null);
            $instr = $this->resolveRef($cache, $ref);
            if ($instr) {
                $instructions = [
                    'title' => $this->str($instr['title'] ?? null),
                    'description' => $this->str($instr['description'] ?? null),
                    'videoUrl' => $instr['videoUrl'] ?? null,
                ];
            }
        }

        // Reviews stats
        $totalReviewCount = is_int($seoPage['totalReviewCount'] ?? 0) ? $seoPage['totalReviewCount'] : 0;
        $starRatingStats = $this->arr($seoPage, 'starRatingStats');

        // Compute average rating from star distribution
        $spRating = null;
        if ($totalReviewCount > 0 && ! empty($starRatingStats)) {
            $weightedSum = 0;
            $totalCount = 0;
            foreach ($starRatingStats as $stat) {
                if (! is_array($stat)) {
                    continue;
                }
                $star = is_int($stat['starRating'] ?? null) ? $stat['starRating'] : 0;
                $total = is_int($stat['total'] ?? null) ? $stat['total'] : 0;
                $weightedSum += $star * $total;
                $totalCount += $total;
            }
            $spRating = $totalCount > 0 ? round($weightedSum / $totalCount, 2) : null;
        }

        // Extract slug from seoEntityPrimary or canonicalUrl
        $entitySlug = $slug;
        $primaryRef = $seoPage['seoEntityPrimary'] ?? null;
        if (is_array($primaryRef) && isset($primaryRef['__ref'])) {
            $entity = $this->resolveRef($cache, $this->str($primaryRef['__ref']));
            if ($entity && isset($entity['slug'])) {
                $entitySlug = $this->str($entity['slug']);
            }
        }

        return [
            'slug' => $entitySlug,
            'name' => $this->str($heroSection['title'] ?? null) ?: $this->str($seoPage['title'] ?? null) ?: $slug,
            'description' => $heroSection['descriptionPrimary'] ?? null,
            'creator' => $heroSection['descriptionSecondary'] ?? null,
            'hero_image' => $heroSection['image'] ?? null,
            'player_range' => $playerRange,
            'mechanic' => $mechanic,
            'genres' => $genres,
            'publisher' => $publisher,
            'publisher_url' => $publisherUrl,
            'release_date' => $releaseDate,
            'faqs' => $faqs,
            'external_links' => $externalLinks,
            'showcases' => $showcases,
            'instructions' => $instructions,
            'total_review_count' => $totalReviewCount,
            'sp_rating' => $spRating,
            'star_rating_stats' => $starRatingStats,
        ];
    }

    /**
     * Parse a genre detail page from the Apollo cache.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    public function parseGenre(array $cache, string $slug): ?array
    {
        $seoPage = $this->findSeoPage($cache, $slug);
        if (! $seoPage) {
            return null;
        }

        $heroSection = $this->arr($seoPage, 'heroSection');

        // Find the SeoEntity for this genre
        $entity = $this->findSeoEntity($cache, $slug, 'GAME_GENRE');

        // Extract popular RPGs from relatedSeoEntities
        $popularRpgs = [];
        if ($entity) {
            $relatedKey = 'relatedSeoEntities({"filter":{"type":{"eq":"GAME_SYSTEM"}}})';
            foreach ($this->arr($entity, $relatedKey) as $related) {
                if (! is_array($related)) {
                    continue;
                }
                $popularRpgs[] = [
                    'slug' => $this->str($related['slug'] ?? null),
                    'title' => $this->str($related['title'] ?? null),
                    'image' => $related['thumbnailImage'] ?? null,
                    'description' => $related['thumbnailDescription'] ?? null,
                ];
            }
        }

        // Extract similar genres from similarSeoEntities
        $similarGenres = [];
        if ($entity) {
            foreach ($this->arr($entity, 'similarSeoEntities({})') as $similar) {
                if (! is_array($similar)) {
                    continue;
                }
                $pages = $this->items($similar['seoPages'] ?? null);
                if (! empty($pages)) {
                    $page = is_array($pages[0]) ? $pages[0] : [];
                    $canonicalUrl = $this->str($page['canonicalUrl'] ?? null);
                    $similarGenres[] = ltrim($canonicalUrl, '/');
                }
            }
        }

        // Resolve FAQs
        $faqs = [];
        foreach ($this->arr($seoPage, 'faqs') as $faqRef) {
            $ref = is_array($faqRef) ? $this->str($faqRef['__ref'] ?? null) : '';
            $faq = $this->resolveRef($cache, $ref);
            if ($faq) {
                $faqs[] = [
                    'questionText' => $this->str($faq['questionText'] ?? null),
                    'answerText' => $this->str($faq['answerText'] ?? null),
                ];
            }
        }

        return [
            'slug' => $slug,
            'name' => $this->str($heroSection['title'] ?? null) ?: $this->str($seoPage['title'] ?? null) ?: $slug,
            'description' => $heroSection['descriptionPrimary'] ?? null,
            'popular_rpgs' => $popularRpgs,
            'similar_genres' => $similarGenres,
            'faqs' => $faqs,
        ];
    }

    /**
     * Parse a mechanic detail page from the Apollo cache.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    public function parseMechanic(array $cache, string $slug): ?array
    {
        $seoPage = $this->findSeoPage($cache, $slug);
        if (! $seoPage) {
            return null;
        }

        $heroSection = $this->arr($seoPage, 'heroSection');

        // Find the SeoEntity for this mechanic
        $entity = $this->findSeoEntity($cache, $slug, 'GAME_MECHANIC');

        // Extract popular RPGs from relatedSeoEntities
        $popularRpgs = [];
        if ($entity) {
            $relatedKey = 'relatedSeoEntities({"filter":{"type":{"eq":"GAME_SYSTEM"}}})';
            foreach ($this->arr($entity, $relatedKey) as $related) {
                if (! is_array($related)) {
                    continue;
                }
                $popularRpgs[] = [
                    'slug' => $this->str($related['slug'] ?? null),
                    'title' => $this->str($related['title'] ?? null),
                    'image' => $related['thumbnailImage'] ?? null,
                    'description' => $related['thumbnailDescription'] ?? null,
                ];
            }
        }

        // Extract similar mechanics from similarSeoEntities
        $similarMechanics = [];
        if ($entity) {
            foreach ($this->arr($entity, 'similarSeoEntities({})') as $similar) {
                if (! is_array($similar)) {
                    continue;
                }
                $pages = $this->items($similar['seoPages'] ?? null);
                if (! empty($pages)) {
                    $page = is_array($pages[0]) ? $pages[0] : [];
                    $canonicalUrl = $this->str($page['canonicalUrl'] ?? null);
                    $similarMechanics[] = ltrim($canonicalUrl, '/');
                }
            }
        }

        return [
            'slug' => $slug,
            'name' => $this->str($heroSection['title'] ?? null) ?: $this->str($seoPage['title'] ?? null) ?: $slug,
            'description' => $heroSection['descriptionPrimary'] ?? null,
            'popular_rpgs' => $popularRpgs,
            'similar_mechanics' => $similarMechanics,
        ];
    }

    /**
     * Parse a playstyle detail page from the Apollo cache.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    public function parseStyle(array $cache, string $slug): ?array
    {
        $seoPage = $this->findSeoPage($cache, $slug);
        if (! $seoPage) {
            return null;
        }

        $heroSection = $this->arr($seoPage, 'heroSection');

        // Find the SeoEntity for this style
        $entity = $this->findSeoEntity($cache, $slug, 'GAME_STYLE');

        // Extract popular RPGs from relatedSeoEntities
        $popularRpgs = [];
        if ($entity) {
            $relatedKey = 'relatedSeoEntities({"filter":{"type":{"eq":"GAME_SYSTEM"}}})';
            foreach ($this->arr($entity, $relatedKey) as $related) {
                if (! is_array($related)) {
                    continue;
                }
                $popularRpgs[] = [
                    'slug' => $this->str($related['slug'] ?? null),
                    'title' => $this->str($related['title'] ?? null),
                    'image' => $related['thumbnailImage'] ?? null,
                    'description' => $related['thumbnailDescription'] ?? null,
                ];
            }
        }

        // Extract similar styles from similarSeoEntities
        $similarStyles = [];
        if ($entity) {
            foreach ($this->arr($entity, 'similarSeoEntities({})') as $similar) {
                if (! is_array($similar)) {
                    continue;
                }
                $pages = $this->items($similar['seoPages'] ?? null);
                if (! empty($pages)) {
                    $page = is_array($pages[0]) ? $pages[0] : [];
                    $canonicalUrl = $this->str($page['canonicalUrl'] ?? null);
                    $similarStyles[] = ltrim($canonicalUrl, '/');
                }
            }
        }

        return [
            'slug' => $slug,
            'name' => $this->str($heroSection['title'] ?? null) ?: $this->str($seoPage['title'] ?? null) ?: $slug,
            'description' => $heroSection['descriptionPrimary'] ?? null,
            'popular_rpgs' => $popularRpgs,
            'similar_styles' => $similarStyles,
        ];
    }

    /**
     * Resolve an Apollo cache __ref pointer to its cached object.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    public function resolveRef(array $cache, string $ref): ?array
    {
        if (empty($ref)) {
            return null;
        }

        $entry = $cache[$ref] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        /** @var array<string, mixed> $entry */
        return $entry;
    }

    /**
     * Find the SeoPage from the cache that corresponds to the given slug.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    private function findSeoPage(array $cache, string $slug): ?array
    {
        $rootQuery = $this->arr($cache, 'ROOT_QUERY');

        // Look for the seoPage query key that contains the slug
        foreach ($rootQuery as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'seoPage(') && str_contains($key, $slug)) {
                if (is_array($value) && isset($value['__ref'])) {
                    return $this->resolveRef($cache, $this->str($value['__ref']));
                }
                // Some pages have the seoPage as null (listing pages)
                if ($value === null) {
                    continue;
                }
            }
        }

        // Fallback: scan all SeoPage entries for matching canonicalUrl or seoEntityPrimary
        foreach ($cache as $key => $value) {
            if (str_starts_with($key, 'SeoPage:') && is_array($value)) {
                $canonicalUrl = $this->str($value['canonicalUrl'] ?? null);
                if (ltrim($canonicalUrl, '/') === $slug) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Find the SeoEntity for a given slug and type.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    private function findSeoEntity(array $cache, string $slug, string $type): ?array
    {
        foreach ($cache as $key => $value) {
            if (
                str_starts_with($key, 'SeoEntity:')
                && is_array($value)
                && (is_string($value['slug'] ?? null) ? $value['slug'] : '') === $slug
                && (is_string($value['type'] ?? null) ? $value['type'] : '') === $type
            ) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Parse heroSection metadata into a keyed array.
     *
     * The metadata is an array of {title, items: [{text, url}]} groups.
     * We key by the group title for easy lookup.
     *
     * @param  array<int, mixed>  $metadata
     * @return array<string, array<int, array{text: string, url: string|null}>>
     */
    private function parseHeroMetadata(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $group) {
            if (! is_array($group)) {
                continue;
            }
            $title = $this->str($group['title'] ?? null);
            $items = [];
            foreach ($this->items($group['items'] ?? null) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'text' => $this->str($item['text'] ?? null),
                    'url' => is_string($item['url'] ?? null) ? $item['url'] : null,
                ];
            }
            $result[$title] = $items;
        }

        return $result;
    }
}
