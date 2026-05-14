<?php

namespace App\Policies\Escalated;

use Illuminate\Support\Facades\Gate;

/**
 * App-level override of the vendor CannedResponsePolicy.
 *
 * The vendor gates CannedResponse behind escalated-agent, but per our
 * visibility matrix, canned response management is a Platform Admin
 * (escalated-admin) concern. Service Admin manages tickets only.
 */
class CannedResponsePolicy
{
    public function viewAny($user): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function view($user, $model): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function create($user): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function update($user, $model): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function delete($user, $model): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }
}
