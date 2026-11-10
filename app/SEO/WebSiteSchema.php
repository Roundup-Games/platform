<?php

namespace App\SEO;

use Illuminate\Support\Collection;
use RalphJSmit\Laravel\SEO\Schema\CustomSchemaFluent;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * WebSite JSON-LD schema with per-locale SearchAction potential actions.
 *
 * Identifies the site to search engines and AI agents and advertises the
 * URL pattern of the platform's search surface, so AI answers can deep
 * link into filtered discovery results (the llms.txt v2 companion signal
 * for agent query routing).
 *
 * Honesty contract: the SearchAction target must be a route that reads
 * its query server-side on the initial render. /{locale}/discover/
 * board-games binds ?q= via ManagesDiscoveryFilters' #[Url(as: 'q')]
 * property, so a plain GET of the template URL returns filtered results
 * with no JavaScript — the target stays honest for users and crawlers
 * alike. If discovery search moves or gains a dedicated sitewide surface,
 * update SEARCH_ROUTE together with its tests.
 *
 * Scalability: locales derive from config('app.available_locales'), so a
 * new locale automatically gains its own SearchAction entry; the search
 * path resolves through the named route (never a path literal), and the
 * base URL follows the app.url config contract used by the sitemap and
 * llms.txt (LlmsTxtService::baseUrl()).
 */
class WebSiteSchema extends CustomSchemaFluent
{
    public string $type = 'WebSite';

    /** @var Collection<string, mixed> */
    public Collection $data;

    /** Route name of the server-rendered search surface each locale links to. */
    public const SEARCH_ROUTE = 'discover.board-games';

    /** Query parameter the search surface reads (ManagesDiscoveryFilters #[Url(as: 'q')]). */
    public const SEARCH_PARAM = 'q';

    public function initializeMarkup(SEOData $SEOData): void
    {
        $baseUrl = $this->baseUrl();

        /** @var array<string, mixed> $items */
        $items = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => is_string($name = config('seo.site_name', config('company.display_name'))) && $name !== '' ? $name : 'Roundup Games',
            'url' => $baseUrl,
            'potentialAction' => $this->searchActions($baseUrl),
        ];

        $this->data = collect($items);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function generateInner(): Collection
    {
        $result = $this->data->pipeThrough($this->markupTransformers);

        return $result instanceof Collection ? $result : collect();
    }

    /**
     * One SearchAction per available locale — every locale's discovery
     * search reads ?q= server-side, so each is a valid, functional target.
     *
     * @return list<array<string, mixed>>
     */
    private function searchActions(string $baseUrl): array
    {
        $locales = config('app.available_locales', ['en']);
        if (! is_array($locales) || $locales === []) {
            $locales = ['en'];
        }

        $actions = [];

        foreach ($locales as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            $actions[] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $baseUrl.route(self::SEARCH_ROUTE, ['locale' => $locale], false).'?'.self::SEARCH_PARAM.'={search_term_string}',
                    'inLanguage' => $locale,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $actions;
    }

    /**
     * Request-independent base URL — config-driven, exactly like
     * LlmsTxtService::baseUrl(); never url(), whose host follows the
     * incoming request.
     */
    private function baseUrl(): string
    {
        $url = config('app.url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : 'https://roundup.games';
    }
}
