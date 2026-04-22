<?php

namespace App\Services\StartPlaying;

class SpParser
{
    /**
     * Parse a game system detail page from the Apollo cache.
     *
     * System pages have a SeoPage with heroSection containing metadata
     * (player range, genres/themes, publisher, release date, mechanic),
     * FAQs, external links, showcases, instructions, and reviews.
     *
     * @param  array<string, mixed>  $cache  The initialCache from SpClient::fetchPage()
     * @param  string  $slug  The system slug (e.g. 'daggerheart')
     * @return array<string, mixed>|null Parsed system data or null if not found.
     */
    public function parseSystem(array $cache, string $slug): ?array
    {
        $seoPage = $this->findSeoPage($cache, $slug);
        if (! $seoPage) {
            return null;
        }

        $heroSection = $seoPage['heroSection'] ?? [];
        $metadata = $this->parseHeroMetadata($heroSection['metadata'] ?? []);

        // Resolve publisher from metadata
        $publisher = null;
        $publisherUrl = null;
        $publisherItems = $metadata['Publisher'] ?? [];
        if (! empty($publisherItems)) {
            $publisher = $publisherItems[0]['text'] ?? null;
            $publisherUrl = $publisherItems[0]['url'] ?? null;
        }

        // Resolve release date
        $releaseDate = null;
        $releaseDateItems = $metadata['Release Date'] ?? [];
        if (! empty($releaseDateItems)) {
            $releaseDate = $releaseDateItems[0]['text'] ?? null;
        }

        // Resolve player range from Details
        $playerRange = null;
        $mechanic = null;
        $detailsItems = $metadata['Details'] ?? [];
        foreach ($detailsItems as $item) {
            $text = $item['text'] ?? '';
            if (preg_match('/\d+.*(?:Player|player)/i', $text)) {
                $playerRange = $text;
            }
            if (! preg_match('/Player/i', $text)) {
                $mechanic = $text;
            }
        }

        // Resolve genres/Themes
        $genres = [];
        $themeItems = $metadata['Themes'] ?? [];
        foreach ($themeItems as $item) {
            $genres[] = $item['text'];
        }

        // Resolve FAQs
        $faqs = [];
        foreach ($seoPage['faqs'] ?? [] as $faqRef) {
            $faq = $this->resolveRef($cache, $faqRef['__ref'] ?? '');
            if ($faq) {
                $faqs[] = [
                    'questionText' => $faq['questionText'] ?? '',
                    'answerText' => $faq['answerText'] ?? '',
                ];
            }
        }

        // Resolve external links
        $externalLinks = [];
        foreach ($seoPage['externalLinks'] ?? [] as $linkRef) {
            $link = $this->resolveRef($cache, $linkRef['__ref'] ?? '');
            if ($link) {
                $externalLinks[] = [
                    'title' => $link['title'] ?? '',
                    'url' => $link['url'] ?? '',
                    'type' => $link['type'] ?? '',
                    'image' => $link['image'] ?? null,
                    'description' => $link['description'] ?? null,
                ];
            }
        }

        // Resolve showcases
        $showcases = [];
        foreach ($seoPage['showcases'] ?? [] as $showcaseRef) {
            $showcase = $this->resolveRef($cache, $showcaseRef['__ref'] ?? '');
            if ($showcase) {
                $items = [];
                foreach ($showcase['items'] ?? [] as $itemRef) {
                    $item = $this->resolveRef($cache, $itemRef['__ref'] ?? '');
                    if ($item) {
                        $items[] = [
                            'title' => $item['title'] ?? '',
                            'description' => $item['description'] ?? '',
                            'image' => $item['image'] ?? null,
                        ];
                    }
                }
                $showcases[] = [
                    'title' => $showcase['title'] ?? '',
                    'items' => $items,
                ];
            }
        }

        // Resolve instructions
        $instructions = null;
        $instructionRefs = $seoPage['instructions'] ?? [];
        if (! empty($instructionRefs)) {
            $instr = $this->resolveRef($cache, $instructionRefs[0]['__ref'] ?? '');
            if ($instr) {
                $instructions = [
                    'title' => $instr['title'] ?? '',
                    'description' => $instr['description'] ?? '',
                    'videoUrl' => $instr['videoUrl'] ?? null,
                ];
            }
        }

        // Reviews stats
        $totalReviewCount = $seoPage['totalReviewCount'] ?? 0;
        $starRatingStats = $seoPage['starRatingStats'] ?? [];

        // Compute average rating from star distribution
        $spRating = null;
        if ($totalReviewCount > 0 && ! empty($starRatingStats)) {
            $weightedSum = 0;
            $totalCount = 0;
            foreach ($starRatingStats as $stat) {
                $star = (int) ($stat['starRating'] ?? 0);
                $total = (int) ($stat['total'] ?? 0);
                $weightedSum += $star * $total;
                $totalCount += $total;
            }
            $spRating = $totalCount > 0 ? round($weightedSum / $totalCount, 2) : null;
        }

        // Extract slug from seoEntityPrimary or canonicalUrl
        $entitySlug = $slug;
        $primaryRef = $seoPage['seoEntityPrimary'] ?? null;
        if (is_array($primaryRef) && isset($primaryRef['__ref'])) {
            $entity = $this->resolveRef($cache, $primaryRef['__ref']);
            if ($entity && isset($entity['slug'])) {
                $entitySlug = $entity['slug'];
            }
        }

        return [
            'slug' => $entitySlug,
            'name' => $heroSection['title'] ?? $seoPage['title'] ?? $slug,
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
     * Genre pages have a SeoPage with heroSection (description),
     * and a SeoEntity with relatedSeoEntities (popular RPGs) and similarSeoEntities.
     *
     * @param  array<string, mixed>  $cache  The initialCache from SpClient::fetchPage()
     * @param  string  $slug  The genre slug (e.g. 'fantasy')
     * @return array<string, mixed>|null Parsed genre data or null if not found.
     */
    public function parseGenre(array $cache, string $slug): ?array
    {
        $seoPage = $this->findSeoPage($cache, $slug);
        if (! $seoPage) {
            return null;
        }

        $heroSection = $seoPage['heroSection'] ?? [];

        // Find the SeoEntity for this genre
        $entity = $this->findSeoEntity($cache, $slug, 'GAME_GENRE');

        // Extract popular RPGs from relatedSeoEntities
        $popularRpgs = [];
        if ($entity) {
            $relatedKey = 'relatedSeoEntities({"filter":{"type":{"eq":"GAME_SYSTEM"}}})';
            foreach ($entity[$relatedKey] ?? [] as $related) {
                $popularRpgs[] = [
                    'slug' => $related['slug'] ?? '',
                    'title' => $related['title'] ?? '',
                    'image' => $related['thumbnailImage'] ?? null,
                    'description' => $related['thumbnailDescription'] ?? null,
                ];
            }
        }

        // Extract similar genres from similarSeoEntities
        $similarGenres = [];
        if ($entity) {
            foreach ($entity['similarSeoEntities({})'] ?? [] as $similar) {
                $pages = $similar['seoPages'] ?? [];
                if (! empty($pages)) {
                    $page = $pages[0];
                    $canonicalUrl = $page['canonicalUrl'] ?? '';
                    $similarGenres[] = ltrim($canonicalUrl, '/');
                }
            }
        }

        // Resolve FAQs
        $faqs = [];
        foreach ($seoPage['faqs'] ?? [] as $faqRef) {
            $faq = $this->resolveRef($cache, $faqRef['__ref'] ?? '');
            if ($faq) {
                $faqs[] = [
                    'questionText' => $faq['questionText'] ?? '',
                    'answerText' => $faq['answerText'] ?? '',
                ];
            }
        }

        return [
            'slug' => $slug,
            'name' => $heroSection['title'] ?? $seoPage['title'] ?? $slug,
            'description' => $heroSection['descriptionPrimary'] ?? null,
            'popular_rpgs' => $popularRpgs,
            'similar_genres' => $similarGenres,
            'faqs' => $faqs,
        ];
    }

    /**
     * Parse a mechanic detail page from the Apollo cache.
     *
     * Mechanic pages follow the same structure as genre pages.
     *
     * @param  array<string, mixed>  $cache  The initialCache from SpClient::fetchPage()
     * @param  string  $slug  The mechanic slug (e.g. 'd20-system')
     * @return array<string, mixed>|null Parsed mechanic data or null if not found.
     */
    public function parseMechanic(array $cache, string $slug): ?array
    {
        $seoPage = $this->findSeoPage($cache, $slug);
        if (! $seoPage) {
            return null;
        }

        $heroSection = $seoPage['heroSection'] ?? [];

        // Find the SeoEntity for this mechanic
        $entity = $this->findSeoEntity($cache, $slug, 'GAME_MECHANIC');

        // Extract popular RPGs from relatedSeoEntities
        $popularRpgs = [];
        if ($entity) {
            $relatedKey = 'relatedSeoEntities({"filter":{"type":{"eq":"GAME_SYSTEM"}}})';
            foreach ($entity[$relatedKey] ?? [] as $related) {
                $popularRpgs[] = [
                    'slug' => $related['slug'] ?? '',
                    'title' => $related['title'] ?? '',
                    'image' => $related['thumbnailImage'] ?? null,
                    'description' => $related['thumbnailDescription'] ?? null,
                ];
            }
        }

        // Extract similar mechanics from similarSeoEntities
        $similarMechanics = [];
        if ($entity) {
            foreach ($entity['similarSeoEntities({})'] ?? [] as $similar) {
                $pages = $similar['seoPages'] ?? [];
                if (! empty($pages)) {
                    $page = $pages[0];
                    $canonicalUrl = $page['canonicalUrl'] ?? '';
                    $similarMechanics[] = ltrim($canonicalUrl, '/');
                }
            }
        }

        return [
            'slug' => $slug,
            'name' => $heroSection['title'] ?? $seoPage['title'] ?? $slug,
            'description' => $heroSection['descriptionPrimary'] ?? null,
            'popular_rpgs' => $popularRpgs,
            'similar_mechanics' => $similarMechanics,
        ];
    }

    /**
     * Resolve an Apollo cache __ref pointer to its cached object.
     *
     * Apollo's normalized cache stores objects with keys like "TypeName:id".
     * References within objects use {"__ref": "TypeName:id"} to point to other objects.
     *
     * @param  array<string, mixed>  $cache  The full initialCache
     * @param  string  $ref  The __ref string (e.g. "CategoryPageFAQ:abc123")
     * @return array<string, mixed>|null The resolved object, or null if not found.
     */
    public function resolveRef(array $cache, string $ref): ?array
    {
        if (empty($ref)) {
            return null;
        }

        $entry = $cache[$ref] ?? null;

        if ($entry === null || ! is_array($entry)) {
            return null;
        }

        return $entry;
    }

    /**
     * Find the SeoPage from the cache that corresponds to the given slug.
     *
     * System detail pages have a ROOT_QUERY with a seoPage key filtered by slug.
     * The ROOT_QUERY value is a __ref pointing to the SeoPage object in the cache.
     */
    private function findSeoPage(array $cache, string $slug): ?array
    {
        $rootQuery = $cache['ROOT_QUERY'] ?? [];

        // Look for the seoPage query key that contains the slug
        foreach ($rootQuery as $key => $value) {
            if (str_starts_with($key, 'seoPage(') && str_contains($key, $slug)) {
                if (is_array($value) && isset($value['__ref'])) {
                    return $this->resolveRef($cache, $value['__ref']);
                }
                // Some pages have the seoPage as null (listing pages)
                if ($value === null) {
                    // For listing pages, there's no single seoPage
                    continue;
                }
            }
        }

        // Fallback: scan all SeoPage entries for matching canonicalUrl or seoEntityPrimary
        foreach ($cache as $key => $value) {
            if (str_starts_with($key, 'SeoPage:') && is_array($value)) {
                $canonicalUrl = $value['canonicalUrl'] ?? '';
                if (ltrim($canonicalUrl, '/') === $slug) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Find the SeoEntity for a given slug and type.
     */
    private function findSeoEntity(array $cache, string $slug, string $type): ?array
    {
        foreach ($cache as $key => $value) {
            if (
                str_starts_with($key, 'SeoEntity:')
                && is_array($value)
                && ($value['slug'] ?? null) === $slug
                && ($value['type'] ?? null) === $type
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
            $title = $group['title'] ?? '';
            $items = [];
            foreach ($group['items'] ?? [] as $item) {
                $items[] = [
                    'text' => $item['text'] ?? '',
                    'url' => $item['url'] ?? null,
                ];
            }
            $result[$title] = $items;
        }

        return $result;
    }
}
