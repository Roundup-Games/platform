<?php

namespace App\Observers;

use App\SEO\IndexNow\IndexNowService;

/**
 * Announces sitemap-relevant entity saves to IndexNow
 * ({@see IndexNowService} — disabled until INDEXNOW_KEY is set).
 *
 * Registered alongside SeoModelObserver/CityHubCacheObserver on the same
 * models in AppServiceProvider: one observer, one concern. Deletions ride
 * the same path — submitting a URL whose page is now gone is the
 * protocol's way of telling engines to drop it.
 */
class IndexNowObserver
{
    public function __construct(
        private readonly IndexNowService $indexNow,
    ) {}

    public function saved(object $model): void
    {
        $this->indexNow->queueFor($model);
    }

    public function deleted(object $model): void
    {
        $this->indexNow->queueFor($model);
    }
}
