<?php

namespace App\SEO;

use RalphJSmit\Laravel\SEO\SchemaCollection;

/**
 * Generates BreadcrumbList JSON-LD schema from the current route context.
 *
 * Integrated via the global SEODataTransformer in AppServiceProvider so every
 * public page automatically receives breadcrumb structured data.
 */
class BreadcrumbBuilder
{
    /**
     * Build breadcrumbs for the current request context.
     *
     * @param  string|null  $pageTitle  Override leaf name (e.g., from SEOData title).
     *                                  Falls back to route parameter derivation.
     * @return array<int, array{name: string, url: string}>
     */
    public function build(?string $pageTitle = null): array
    {
        $route = request()->route();
        if (! $route) {
            return [$this->homeCrumb()];
        }

        $routeName = $route->getName();
        $params = $route->parameters();

        return match ($routeName) {
            'game-systems' => $this->crumbs([
                [__('games.content_game_systems'), route('game-systems')],
            ]),
            'game-systems.show' => $this->crumbs([
                [__('games.content_game_systems'), route('game-systems')],
                [$this->resolveLeafName($params, $pageTitle), request()->url()],
            ]),

            'events.index' => $this->crumbs([
                [__('events.content_events'), route('events.index')],
            ]),
            'events.detail' => $this->crumbs([
                [__('events.content_events'), route('events.index')],
                [$this->resolveLeafName($params, $pageTitle), request()->url()],
            ]),

            'games.index' => $this->crumbs([
                [__('games.content_games'), route('games.index')],
            ]),
            'games.detail' => $this->crumbs([
                [__('games.content_games'), route('games.index')],
                [$this->resolveLeafName($params, $pageTitle), request()->url()],
            ]),

            'campaigns.index' => $this->crumbs([
                [__('campaigns.content_campaigns'), route('campaigns.index')],
            ]),
            'campaigns.detail' => $this->crumbs([
                [__('campaigns.content_campaigns'), route('campaigns.index')],
                [$this->resolveLeafName($params, $pageTitle), request()->url()],
            ]),

            'teams.browse' => $this->crumbs([
                [__('events.content_teams'), route('teams.browse')],
            ]),
            'teams.detail' => $this->crumbs([
                [__('events.content_teams'), route('teams.browse')],
                [$this->resolveLeafName($params, $pageTitle), request()->url()],
            ]),

            'gm.directory' => $this->crumbs([
                [__('profile.nav_gm_directory'), route('gm.directory')],
            ]),

            'discover' => $this->crumbs([
                [__('discovery.action_discover'), route('discover')],
            ]),
            'discover.board-games' => $this->crumbs([
                [__('discovery.action_discover'), route('discover')],
                [__('games.action_discover_board_games'), request()->url()],
            ]),
            'discover.adventures' => $this->crumbs([
                [__('discovery.action_discover'), route('discover')],
                [__('games.action_discover_adventures'), request()->url()],
            ]),

            'profile.public' => $this->crumbs([
                [__('profile.nav_gm_directory'), route('gm.directory')],
                [$this->resolveLeafName($params, $pageTitle), request()->url()],
            ]),

            'pledge' => $this->crumbs([
                [__('common.nav_our_pledge'), route('pledge')],
            ]),
            'pledge.algorithms' => $this->crumbs([
                [__('common.nav_our_pledge'), route('pledge')],
                [__('pages.content_pledge_card_algorithms_title'), request()->url()],
            ]),
            'pledge.finances' => $this->crumbs([
                [__('common.nav_our_pledge'), route('pledge')],
                [__('pages.content_pledge_card_finances_title'), request()->url()],
            ]),
            'pledge.roadmap' => $this->crumbs([
                [__('common.nav_our_pledge'), route('pledge')],
                [__('pages.content_pledge_card_roadmap_title'), request()->url()],
            ]),
            'pledge.operations' => $this->crumbs([
                [__('common.nav_our_pledge'), route('pledge')],
                [__('pages.content_pledge_card_operations_title'), request()->url()],
            ]),

            default => [$this->homeCrumb()],
        };
    }

    /**
     * Create a SchemaCollection with breadcrumbs attached.
     *
     * @return SchemaCollection<int|string>
     */
    public function buildSchemaCollection(?string $pageTitle = null): SchemaCollection
    {
        $breadcrumbs = $this->build($pageTitle);

        return SchemaCollection::initialize()->addBreadcrumbs(function ($schema) use ($breadcrumbs) {
            $schema->breadcrumbs = collect();
            foreach ($breadcrumbs as $crumb) {
                $schema->breadcrumbs->put($crumb['name'], $crumb['url']);
            }
        });
    }

    /**
     * Prepend Home breadcrumb to the given items.
     *
     * @param  array<int, array{0: string, 1: string}>  $items
     * @return array<int, array{name: string, url: string}>
     */
    protected function crumbs(array $items): array
    {
        $result = [$this->homeCrumb()];

        foreach ($items as [$name, $url]) {
            $result[] = ['name' => $name, 'url' => $url];
        }

        return $result;
    }

    /**
     * Localized Home breadcrumb item.
     *
     * @return array{name: string, url: string}
     */
    protected function homeCrumb(): array
    {
        return ['name' => __('common.content_home'), 'url' => url('/')];
    }

    /**
     * Resolve the leaf (current page) breadcrumb name from route parameters.
     *
     * @param  string|null  $pageTitle  Title from SEOData, used for UUID routes.
     * @param  array<string, mixed>  $params
     */
    protected function resolveLeafName(array $params, ?string $pageTitle = null): string
    {
        if (isset($params['slug']) && is_string($params['slug'])) {
            return $this->slugToTitle($params['slug']);
        }

        if (isset($params['username']) && is_string($params['username'])) {
            return $params['username'];
        }

        // For UUID-based routes, use the page title from SEOData
        if (isset($params['id'])) {
            if ($pageTitle) {
                // Strip any configured title suffix (e.g., " | Roundup Games")
                $suffix = config('seo.title.suffix', '');
                if (is_string($suffix) && $suffix && str_ends_with($pageTitle, $suffix)) {
                    $pageTitle = substr($pageTitle, 0, -strlen($suffix));
                }

                return trim($pageTitle);
            }

            // Last resort: last path segment (only useful for non-UUID paths)
            return $this->slugToTitle(basename(request()->path()));
        }

        return $this->slugToTitle(basename(request()->path()));
    }

    /**
     * Convert a slug to a human-readable title.
     */
    protected function slugToTitle(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
