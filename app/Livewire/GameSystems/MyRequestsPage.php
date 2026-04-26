<?php

namespace App\Livewire\GameSystems;

use App\Models\GameSystemRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class MyRequestsPage extends Component
{
    use WithPagination;

    protected const PER_PAGE = 12;

    // ── Lifecycle ─────────────────────────────────────

    public function mount(): void
    {
        if (! Auth::check()) {
            redirect()->route('login');
        }
    }

    // ── Query ─────────────────────────────────────────

    protected function getRequests(): LengthAwarePaginator
    {
        return GameSystemRequest::query()
            ->where('user_id', Auth::id())
            ->with('gameSystem')
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE);
    }

    // ── Helpers ───────────────────────────────────────

    public function getStatusColor(string $status): string
    {
        return match ($status) {
            'pending', 'in_review' => 'yellow',
            'approved' => 'green',
            'rejected' => 'red',
            'duplicate' => 'blue',
            default => 'gray',
        };
    }

    public function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('games.request_status_pending'),
            'in_review' => __('games.request_status_in_review'),
            'approved' => __('games.request_status_approved'),
            'rejected' => __('games.request_status_rejected'),
            'duplicate' => __('games.request_status_duplicate'),
            default => ucfirst($status),
        };
    }

    public function getTypeLabel(string $type): string
    {
        return match ($type) {
            'boardgame' => __('games.type_board_game'),
            'ttrpg' => __('games.type_ttrpg'),
            'other' => __('games.type_other'),
            default => ucfirst($type),
        };
    }

    // ── Render ────────────────────────────────────────

    public function render()
    {
        return view('livewire.game-systems.my-requests-page', [
            'requests' => $this->getRequests(),
        ]);
    }
}
