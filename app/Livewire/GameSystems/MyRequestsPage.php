<?php

namespace App\Livewire\GameSystems;

use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Ticket;
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
        return Ticket::query()
            ->where('requester_type', \App\Models\User::class)
            ->where('requester_id', Auth::id())
            ->where('ticket_type', 'game_system_request')
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE);
    }

    // ── Helpers ───────────────────────────────────────

    public function getStatusColor(Ticket $ticket): string
    {
        $status = $this->mapTicketStatus($ticket);

        return match ($status) {
            'pending', 'in_review' => 'yellow',
            'approved' => 'green',
            'rejected', 'duplicate' => 'red',
            default => 'gray',
        };
    }

    public function getStatusLabel(Ticket $ticket): string
    {
        $status = $this->mapTicketStatus($ticket);

        return match ($status) {
            'pending' => __('games.request_status_pending'),
            'in_review' => __('games.request_status_in_review'),
            'approved' => __('games.request_status_approved'),
            'rejected' => __('games.request_status_rejected'),
            'duplicate' => __('games.request_status_duplicate'),
            default => ucfirst($status),
        };
    }

    public function getTypeLabel(Ticket $ticket): string
    {
        $type = $ticket->metadata['game_system_type'] ?? 'other';

        return match ($type) {
            'boardgame' => __('games.type_board_game'),
            'ttrpg' => __('games.type_ttrpg'),
            'other' => __('games.type_other'),
            default => ucfirst($type),
        };
    }

    public function getRequestName(Ticket $ticket): string
    {
        $subject = $ticket->subject ?? '';

        if (str_starts_with($subject, 'Game System Request: ')) {
            return trim(\Illuminate\Support\Str::after($subject, 'Game System Request: '));
        }

        return trim($subject);
    }

    public function getGameSystem(Ticket $ticket): ?\App\Models\GameSystem
    {
        $gameSystemId = $ticket->metadata['game_system_id'] ?? null;

        if (! $gameSystemId) {
            return null;
        }

        return \App\Models\GameSystem::find($gameSystemId);
    }

    public function getRejectionReason(Ticket $ticket): ?string
    {
        if ($this->mapTicketStatus($ticket) !== 'rejected') {
            return null;
        }

        return $ticket->metadata['rejection_reason'] ?? null;
    }

    // ── Render ────────────────────────────────────────

    public function render()
    {
        return view('livewire.game-systems.my-requests-page', [
            'requests' => $this->getRequests(),
        ]);
    }

    // ── Private ───────────────────────────────────────

    public function mapTicketStatus(Ticket $ticket): string
    {
        $status = $ticket->status instanceof TicketStatus
            ? $ticket->status
            : TicketStatus::tryFrom($ticket->status);

        if ($status === TicketStatus::Open || $status === TicketStatus::InProgress) {
            return 'pending';
        }

        if ($status === TicketStatus::Resolved) {
            return 'approved';
        }

        if ($status === TicketStatus::Closed) {
            // Check metadata for close reason
            $metadata = $ticket->metadata ?? [];

            return $metadata['close_reason'] ?? 'rejected';
        }

        return 'pending';
    }
}
