<?php

namespace App\Policies\Escalated;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function view(User $user, Model $model): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function update(User $user, Model $model): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }

    public function delete(User $user, Model $model): bool
    {
        return Gate::forUser($user)->allows('escalated-admin');
    }
}
