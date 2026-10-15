<?php

namespace App\SEO\IndexNow;

use App\Dto\CitySummary;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Http\Controllers\SitemapController;
use App\Models\Campaign;
use App\Models\City;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\CityDirectoryService;
use App\Services\LocationDisclosureService;

/**
 * Maps a saved model to the public URLs IndexNow should be told about
 * (one per locale), mirroring the sitemap builders' indexability rules —
 * the same authority that decides what belongs in the sitemap decides
 * what is worth pinging. Returns [] when the model has no indexable page.
 */
final class ResolveIndexNowUrls
{
    private const LOCALES = ['en', 'de'];

    public function __construct(
        private readonly CityDirectoryService $cityDirectory,
        private readonly LocationDisclosureService $disclosure,
    ) {}

    /**
     * @return list<string> absolute URLs for every locale variant
     */
    public function for(object $model): array
    {
        return match (true) {
            $model instanceof GameSystem => $this->gameSystem($model),
            $model instanceof Event => $this->event($model),
            $model instanceof Game => $this->game($model),
            $model instanceof Campaign => $this->campaign($model),
            $model instanceof User => $this->user($model),
            $model instanceof Location => $this->venue($model),
            $model instanceof City => $this->city($model),
            default => [],
        };
    }

    /**
     * @param  list<string>  $paths  locale-relative paths, e.g. 'game-systems/x'
     * @return list<string>
     */
    private function urls(array $paths): array
    {
        $urls = [];

        foreach (self::LOCALES as $locale) {
            foreach ($paths as $path) {
                $urls[] = url("{$locale}/{$path}");
            }
        }

        return $urls;
    }

    /**
     * BGG duplicates canonicalize away (SEO 2026-10-15) — only the
     * canonical row's URLs are worth announcing.
     */
    /**
     * @return list<string>
     */
    private function gameSystem(GameSystem $system): array
    {
        if ($system->canonical_game_system_id !== null || blank($system->slug)) {
            return [];
        }

        return $this->urls(["game-systems/{$system->slug}"]);
    }

    /**
     * @return list<string>
     */
    private function event(Event $event): array
    {
        if (! in_array($event->status?->value, SitemapController::PUBLISHED_EVENT_STATUSES, true) || blank($event->slug)) {
            return [];
        }

        return $this->urls(["events/{$event->slug}"]);
    }

    /**
     * @return list<string>
     */
    private function game(Game $game): array
    {
        if ($game->visibility !== Visibility::Public || $game->status === GameStatus::Canceled) {
            return [];
        }

        return $this->urls(["games/{$game->id}"]);
    }

    /**
     * @return list<string>
     */
    private function campaign(Campaign $campaign): array
    {
        if ($campaign->visibility !== Visibility::Public || $campaign->status === CampaignStatus::Cancelled) {
            return [];
        }

        return $this->urls(["campaigns/{$campaign->id}"]);
    }

    /**
     * @return list<string>
     */
    private function user(User $user): array
    {
        if (! $user->profile_complete || blank($user->slug) || $user->is_disabled || $user->anonymized_at !== null) {
            return [];
        }

        return $this->urls(["u/{$user->slug}"]);
    }

    /**
     * @return list<string>
     */
    private function venue(Location $location): array
    {
        if (blank($location->slug) || ! $this->disclosure->isPublicVenuePage($location)) {
            return [];
        }

        return $this->urls(["venue/{$location->slug}"]);
    }

    /**
     * @return list<string>
     */
    private function city(City $city): array
    {
        // Same qualification guard that gates the /{locale}/cities/{slug}
        // route — a non-qualifying city 404s, so its URL is never announced.
        // qualifyingCities() yields CitySummary DTOs; slug is the identity.
        $qualifies = $this->cityDirectory->qualifyingCities()
            ->contains(fn (CitySummary $summary): bool => $summary->slug === $city->slug);

        if (! $qualifies) {
            return [];
        }

        return $this->urls(["cities/{$city->slug}"]);
    }
}
