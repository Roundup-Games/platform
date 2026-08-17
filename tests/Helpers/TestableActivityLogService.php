<?php

namespace Tests\Helpers;

use App\Enums\ActivityType;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * Testable ActivityLogService that records PostHog forwarding calls.
 * Overrides forwardToPostHog() to capture arguments instead of resolving
 * the real bridge from the container.
 */
class TestableActivityLogService extends ActivityLogService
{
    public array $posthogCalls = [];

    protected function forwardToPostHog(
        ActivityType $type,
        User $user,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        $this->posthogCalls[] = [
            'type' => $type,
            'user' => $user,
            'subject' => $subject,
            'properties' => $properties,
        ];
    }
}
