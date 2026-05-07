<?php

namespace App\Traits;

use App\Services\BenchService;
use Illuminate\Support\Facades\Auth;

trait HandlesBench
{
    public function promoteFromBench(string $participantId): void
    {
        $viewer = Auth::user();

        if (! $viewer || $this->getEntity()->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($this->getEntity()->campaign_id === null) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        try {
            app(BenchService::class)->promoteFromBench($participantId, 'game');
            session()->flash('success', __('games.flash_promote_from_bench_success'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }
}
