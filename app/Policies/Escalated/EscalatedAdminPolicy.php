<?php

namespace App\Policies\Escalated;

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
