<?php

namespace App\Livewire\GameSystems;

use App\Services\GameSystemRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class RequestGameSystemPage extends Component
{
    #[Url(as: 'name')]
    public string $name = '';

    #[Url(as: 'type')]
    public string $type = 'boardgame';

    public ?string $bgg_url = null;

    public ?string $publisher = null;

    public ?string $designer = null;

    public ?string $notes = null;

    public bool $submitted = false;

    // ── Lifecycle ─────────────────────────────────────

    public function mount(): void
    {
        // Redirect unauthenticated users
        if (! Auth::check()) {
            $this->redirectRoute('login');
        }
    }

    // ── Validation ────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:boardgame,ttrpg,other',
            'bgg_url' => 'nullable|string|url|max:500',
            'publisher' => 'nullable|string|max:255',
            'designer' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    // ── Actions ───────────────────────────────────────

    public function submit(): void
    {
        $this->validate();

        $user = authenticatedUser();

        $service = app(GameSystemRequestService::class);

        $normalizedName = trim($this->name);

        // Check for duplicate pending request from same user
        if ($service->hasPendingRequest($user, $normalizedName)) {
            $this->addError('name', __('games.request_error_duplicate'));
            logger()->info('Game system request duplicate attempt', [
                'user_id' => $user->id,
                'name' => $normalizedName,
            ]);

            return;
        }

        // Rate limit: 3 requests per day per user
        $rateLimitKey = 'game-system-request:'.$user->id;

        if (! RateLimiter::attempt($rateLimitKey, 3, fn () => true, decaySeconds: 86400)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $hours = ceil($seconds / 3600);
            $this->addError('name', __('games.request_error_rate_limit', ['hours' => $hours]));
            logger()->info('Game system request rate limit hit', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $service->createRequest($user, [
            'name' => $normalizedName,
            'type' => $this->type,
            'bgg_url' => $this->bgg_url,
            'publisher' => $this->publisher,
            'designer' => $this->designer,
            'notes' => $this->notes,
        ]);

        $this->reset(['name', 'bgg_url', 'publisher', 'designer', 'notes']);
        $this->submitted = true;

        session()->flash('success', __('games.request_success'));
    }

    // ── Render ────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.game-systems.request-game-system-page');
    }
}
