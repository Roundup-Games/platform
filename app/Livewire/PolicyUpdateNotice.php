<?php

namespace App\Livewire;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PolicyUpdateNotice extends Component
{
    public bool $showNotice = false;

    public function mount(): void
    {
        $user = authenticatedUser();

        $dismissalKey = $this->dismissalKey($user);
        if (session($dismissalKey)) {
            $this->showNotice = false;

            return;
        }

        $privacyUpdated = Carbon::parse(is_string($pv = config('policies.privacy.last_updated')) ? $pv : now());
        $termsUpdated = Carbon::parse(is_string($tv = config('policies.terms.last_updated')) ? $tv : now());

        $privacyNeedsAccept = $user->privacy_policy_accepted_at === null
            || $user->privacy_policy_accepted_at->lt($privacyUpdated);

        $termsNeedsAccept = $user->terms_accepted_at === null
            || $user->terms_accepted_at->lt($termsUpdated);

        $this->showNotice = $privacyNeedsAccept || $termsNeedsAccept;
    }

    public function accept(): void
    {
        $user = authenticatedUser();

        $updates = [];

        $privacyUpdated = Carbon::parse(is_string($pv2 = config('policies.privacy.last_updated')) ? $pv2 : now());
        if ($user->privacy_policy_accepted_at === null
            || $user->privacy_policy_accepted_at->lt($privacyUpdated)) {
            $updates['privacy_policy_accepted_at'] = now();
        }

        $termsUpdated = Carbon::parse(is_string($tv2 = config('policies.terms.last_updated')) ? $tv2 : now());
        if ($user->terms_accepted_at === null
            || $user->terms_accepted_at->lt($termsUpdated)) {
            $updates['terms_accepted_at'] = now();
        }

        if (! empty($updates)) {
            $user->update($updates);
        }

        $this->showNotice = false;
    }

    public function dismiss(): void
    {
        $user = authenticatedUser();
        session()->put($this->dismissalKey($user), true);
        $this->showNotice = false;
    }

    public function render(): View
    {
        $user = authenticatedUser();
        if (session($this->dismissalKey($user))) {
            $this->showNotice = false;
        }

        return view('livewire.policy-update-notice');
    }

    /**
     * Build a dismissal key scoped to the user and current policy versions,
     * so a new policy update re-shows the notice even within the same session.
     */
    private function dismissalKey(User $user): string
    {
        $privacyVersion = is_string($pv3 = config('policies.privacy.last_updated', '')) ? $pv3 : '';
        $termsVersion = is_string($tv3 = config('policies.terms.last_updated', '')) ? $tv3 : '';

        return "policy_notice_dismissed.{$user->id}.{$privacyVersion}.{$termsVersion}";
    }
}
