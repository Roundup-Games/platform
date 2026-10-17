<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Queue-degradation alerting (D182, user-approved 2026-10-17): without
        // a routed channel, Horizon's LongWaitDetected / failed-job / down /
        // missing-supervisor notifications notify nobody. Mail goes to the
        // operations address already receiving the data:audit failure emails.
        $operationsMailbox = config('mail.from.address');

        if (is_string($operationsMailbox) && $operationsMailbox !== '') {
            Horizon::routeMailNotificationsTo($operationsMailbox);
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user && $user->isAdmin();
        });
    }
}
