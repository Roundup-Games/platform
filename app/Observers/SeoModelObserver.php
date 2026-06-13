<?php

namespace App\Observers;

use App\Models\User;
use App\Services\SeoCacheService;

/**
 * Observes model changes that affect sitemap content and invalidates
 * the relevant sitemap caches via SeoCacheService.
 *
 * A single observer class registered against all sitemap-relevant models
 * in AppServiceProvider. Uses generic saved/deleted methods that delegate
 * to SeoCacheService::forgetByModel() for model-to-type mapping.
 *
 * User saved is selective — only invalidates when slug or
 * visibility-related fields (profile_complete, is_disabled) change.
 * The wasRecentlyCreated flag is intentionally NOT checked because it
 * persists across operations on the same model instance (create then
 * update on the same PHP object). Instead, new users that get an
 * auto-generated slug will trigger wasChanged('slug') = true.
 */
class SeoModelObserver
{
    public function __construct(
        private SeoCacheService $seoCache,
    ) {}

    /**
     * Handle the "saved" event for any observed model.
     *
     * For User models, only invalidate when slug or visibility fields change
     * to avoid unnecessary cache clears on unrelated updates (e.g. last_login).
     */
    public function saved(object $model): void
    {
        if ($this->shouldSkipUserSave($model)) {
            return;
        }

        $this->seoCache->forgetByModel($model);
    }

    /**
     * Handle the "deleted" event for any observed model.
     */
    public function deleted(object $model): void
    {
        $this->seoCache->forgetByModel($model);
    }

    /**
     * Check if this is a User model save that doesn't affect sitemap content.
     *
     * We do NOT use wasRecentlyCreated because it persists across operations
     * on the same model instance. Instead, we rely on wasChanged() — new users
     * that get an auto-generated slug via the creating hook will have
     * wasChanged('slug') = true, triggering invalidation correctly.
     */
    private function shouldSkipUserSave(object $model): bool
    {
        if (! ($model instanceof User)) {
            return false;
        }

        // Only invalidate when sitemap-relevant fields change
        return ! $model->wasChanged('slug')
            && ! $model->wasChanged('profile_complete')
            && ! $model->wasChanged('is_disabled');
    }
}
