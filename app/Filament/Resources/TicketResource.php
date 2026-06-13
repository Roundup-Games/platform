<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use Escalated\Filament\Resources\TicketResource as BaseTicketResource;
use Escalated\Filament\Resources\TicketResource\Pages\CreateTicket;
use Escalated\Filament\Resources\TicketResource\Pages\ListTickets;

/**
 * Application-level TicketResource extending the Escalated vendor resource.
 *
 * Overrides getPages() to use a custom ViewTicket page with game-system-specific
 * BGG sync actions. All other resource behavior (table, form, filters, relations)
 * is inherited from the vendor resource unchanged.
 */
class TicketResource extends BaseTicketResource
{
    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
