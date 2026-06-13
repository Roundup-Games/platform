<?php

namespace App\Policies\Escalated;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Generic admin-only policy for Escalated resources that have no vendor policy.
 *
 * Used for: Macro, ApiToken, Automation, Webhook, Role, TicketStatus,
 * Skill, CustomField, BusinessSchedule, Article, ArticleCategory, AuditLog.
 *
 * All CRUD operations require the escalated-admin gate (Platform Admin only).
 */
class EscalatedAdminPolicy
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
