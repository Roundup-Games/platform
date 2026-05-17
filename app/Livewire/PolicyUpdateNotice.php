<?php

namespace App\Livewire;

use Livewire\Component;

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

        $privacyUpdated = config('policies.privacy.last_updated');
        $termsUpdated = config('policies.terms.last_updated');

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

        $user->update([
            'privacy_policy_accepted_at' => now(),
            'terms_accepted_at' => now(),
        ]);

        $this->showNotice = false;
    }

    public function dismiss(): void
    {
        // Dismiss for this session only — notice reappears on next session
        session()->flash('policy_notice_dismissed', true);
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
