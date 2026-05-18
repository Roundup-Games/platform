<?php

namespace App\Livewire;

use Livewire\Component;
use Carbon\Carbon;

class PolicyUpdateNotice extends Component
{
    public bool $showNotice = false;

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->showNotice = false;
            return;
        }

        $privacyUpdated = Carbon::parse(config('policies.privacy.last_updated'));
        $termsUpdated = Carbon::parse(config('policies.terms.last_updated'));

        $privacyNeedsAccept = $privacyUpdated
            && ($user->privacy_policy_accepted_at === null
                || $user->privacy_policy_accepted_at->lt($privacyUpdated));

        $termsNeedsAccept = $termsUpdated
            && ($user->terms_accepted_at === null
                || $user->terms_accepted_at->lt($termsUpdated));

        $this->showNotice = $privacyNeedsAccept || $termsNeedsAccept;
    }

    public function accept(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $updates = [];

        $privacyUpdated = Carbon::parse(config('policies.privacy.last_updated'));
        if ($privacyUpdated
            && ($user->privacy_policy_accepted_at === null
                || $user->privacy_policy_accepted_at->lt($privacyUpdated))) {
            $updates['privacy_policy_accepted_at'] = now();
        }

        $termsUpdated = Carbon::parse(config('policies.terms.last_updated'));
        if ($termsUpdated
            && ($user->terms_accepted_at === null
                || $user->terms_accepted_at->lt($termsUpdated))) {
            $updates['terms_accepted_at'] = now();
        }

        if (! empty($updates)) {
            $user->update($updates);
        }

        $this->showNotice = false;
    }

    public function dismiss(): void
    {
        // Persist for the full session (not just one request). Flash data
        // is consumed on the next request, so wire:navigate would immediately
        // re-show the notice. session()->put survives until the session ends.
        session()->put('policy_notice_dismissed', true);
        $this->showNotice = false;
    }

    public function render()
    {
        // Respect session-dismiss for this request cycle
        if (session('policy_notice_dismissed')) {
            $this->showNotice = false;
        }

        return view('livewire.policy-update-notice');
    }
}
