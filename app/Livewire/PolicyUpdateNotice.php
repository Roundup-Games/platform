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

        $dismissalKey = $this->dismissalKey($user);
        if (session($dismissalKey)) {
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
        $user = auth()->user();
        if ($user) {
            session()->put($this->dismissalKey($user), true);
        }
        $this->showNotice = false;
    }

    public function render()
    {
        $user = auth()->user();
        if ($user && session($this->dismissalKey($user))) {
            $this->showNotice = false;
        }

        return view('livewire.policy-update-notice');
    }

    /**
     * Build a dismissal key scoped to the user and current policy versions,
     * so a new policy update re-shows the notice even within the same session.
     */
    private function dismissalKey($user): string
    {
        $privacyVersion = config('policies.privacy.last_updated', '');
        $termsVersion = config('policies.terms.last_updated', '');

        return "policy_notice_dismissed.{$user->id}.{$privacyVersion}.{$termsVersion}";
    }
}
