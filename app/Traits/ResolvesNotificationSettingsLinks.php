<?php

namespace App\Traits;

use App\Dto\NotificationGroup;
use App\Enums\NotificationCategory;

/**
 * Per-row settings shortcut for in-app notification rows.
 *
 * Both notification surfaces (bell dropdown and the notifications history
 * page) expose a "manage" affordance per row that deep-links the recipient to
 * the exact preference row governing what they just received — anchored via
 * #category-{value} on the settings page's notification-preference sections.
 *
 * The row's family is resolved from the STORED data.type payload (the counter
 * vocabulary, D284) via NotificationCategory::preferenceFamilyForType(), so
 * type-only variants (recap_posted → session_content, …) land on the right
 * preference family, while administrative one-offs with no preference row
 * (account_suspended, digest, …) yield null and the affordance is omitted.
 */
trait ResolvesNotificationSettingsLinks
{
    /**
     * Build the per-category settings shortcut for a grouped notification
     * row, or null when the row has no resolvable preference family.
     *
     * @return array{url: string, label: string}|null url — settings route +
     *                                                #category-{value} anchor;
     *                                                label — localized category label for aria
     */
    public function categorySettingsLink(NotificationGroup $group): ?array
    {
        $type = $group->latest->data['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        $family = NotificationCategory::preferenceFamilyForType($type);

        if ($family === null) {
            return null;
        }

        return [
            'url' => route('settings.show', app()->getLocale()).'#category-'.$family->value,
            'label' => $family->label(),
        ];
    }
}
