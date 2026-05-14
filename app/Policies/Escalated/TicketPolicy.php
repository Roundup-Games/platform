<?php

namespace App\Policies\Escalated;

use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Gate;

/**
 * App-level override of the vendor TicketPolicy.
 *
 * The vendor policy returns true from viewAny() unconditionally, which would
 * show ticket navigation to every panel user. We override viewAny to gate
 * behind the escalated-agent gate (Platform Admin + Service Admin).
 *
 * All other methods (view, create, update, reply, assign, close, etc.) are
 * inherited from the vendor policy and remain unchanged.
 */
class TicketPolicy extends \Escalated\Laravel\Policies\TicketPolicy
{
    public function viewAny($user): bool
    {
        return Gate::forUser($user)->allows('escalated-agent');
    }
}
