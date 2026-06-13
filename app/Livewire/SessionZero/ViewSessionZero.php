<?php

namespace App\Livewire\SessionZero;

use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * @property-read bool $isGm
 * @property-read Collection<int, SessionZeroConfirmation> $confirmations
 */
#[Layout('layouts.app')]
class ViewSessionZero extends Component
{
    #[Locked]
    public string $uuid;

    public bool $confirming = false;

    public bool $confirmed = false;

    public ?string $confirmedAt = null;

    private ?SessionZeroSurvey $resolvedSurvey = null;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        $survey = $this->resolveSurvey();

        if (! $survey) {
            abort(404);
        }

        // Check if current user already confirmed
        $user = Auth::user();
        if ($user) {
            $existing = $survey->confirmations()->where('user_id', $user->id)->first();
            if ($existing) {
                $this->confirmed = true;
                $this->confirmedAt = $existing->confirmed_at?->format('F j, Y \a\t g:i A');
            }
        }
    }

    public function confirm(): void
    {
        $user = authenticatedUser();

        $survey = $this->resolveSurvey();

        if (! $survey) {
            abort(404);
        }

        // Idempotency: already confirmed
        if ($this->confirmed) {
            return;
        }

        $this->confirming = true;

        // Double-check no existing confirmation
        $exists = $survey->confirmations()->where('user_id', $user->id)->exists();
        if ($exists) {
            $this->confirmed = true;
            $this->confirming = false;

            return;
        }

        $confirmation = $survey->confirmations()->create([
            'user_id' => $user->id,
        ]);

        $survey->incrementConfirmationCount();

        Log::info('Session Zero confirmation recorded', [
            'survey_id' => $survey->id,
            'uuid' => $survey->uuid,
            'user_id' => $user->id,
            'confirmation_id' => $confirmation->id,
        ]);

        $this->confirmed = true;
        $this->confirmedAt = $confirmation->confirmed_at?->format('F j, Y \a\t g:i A');
        $this->confirming = false;
    }

    public function getSurveyProperty(): ?SessionZeroSurvey
    {
        return $this->resolveSurvey();
    }

    public function getIsGmProperty(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $this->resolveSurvey()?->gmProfile?->user_id === $user->id;
    }

    /** @return Collection<int, SessionZeroConfirmation> */
    public function getConfirmationsProperty(): Collection
    {
        if (! $this->isGm) {
            return new Collection;
        }

        return $this->resolveSurvey()
            ?->confirmations()
            ->with('user')
            ->orderByDesc('confirmed_at')
            ->get() ?? new Collection;
    }

    public function render(): View
    {
        $survey = $this->resolveSurvey();
        $isGm = $this->isGm;
        $confirmations = $this->confirmations;

        return view('livewire.session-zero.view-session-zero', [
            'survey' => $survey,
            'isGm' => $isGm,
            'confirmations' => $confirmations,
        ]);
    }

    private function resolveSurvey(): ?SessionZeroSurvey
    {
        if ($this->resolvedSurvey === null) {
            $this->resolvedSurvey = SessionZeroSurvey::findByUuid($this->uuid);
        }

        return $this->resolvedSurvey;
    }
}
