<?php

namespace App\Support;

/**
 * Machine slug → human label resolution for moderation notices
 * (M081 follow-up: the email-copy UX audit's finding #1).
 *
 * Admin moderation mails (ContentRemoved, ContentReportWarning,
 * AccountSuspended) receive the report picker's reason slug
 * ('inappropriate-content', 'harassment', …) and the reported entity's type
 * slug ('game'/'campaign'/'profile'/'content') straight from ticket metadata.
 * Rendered raw, a suspended user read "Grund: inappropriate-content". The
 * notifications now resolve both through this map in the RECIPIENT's locale
 * at render time. Unknown machine tokens (no spaces) never echo through:
 * they degrade to a generic label; genuine free text passes untouched.
 */
final class ModerationNoticeLabels
{
    /**
     * Report-picker slugs → label keys (ReportContent::REASONS plus the
     * review-reporter's 'inappropriate' variant — same meaning, shorter slug).
     *
     * @var array<string, string>
     */
    private const REASON_KEYS = [
        'inappropriate-content' => 'reports.field_reason_inappropriate_content',
        'inappropriate' => 'reports.field_reason_inappropriate_content',
        'harassment' => 'reports.field_reason_harassment',
        'spam' => 'reports.field_reason_spam',
        'misleading' => 'reports.field_reason_misleading',
        'other' => 'common.content_other',
    ];

    /** Fallback reason used by the admin actions when ticket metadata carries none. */
    private const string REASON_FALLBACK = 'community guidelines violation';

    private const string REASON_FALLBACK_KEY = 'notifications.field_reason_community_guidelines';

    /**
     * Reported-entity type slugs → label keys.
     *
     * @var array<string, string>
     */
    private const ENTITY_TYPE_KEYS = [
        'game' => 'notifications.entity_type_game',
        'campaign' => 'notifications.entity_type_campaign',
        'team' => 'notifications.entity_type_team',
        'profile' => 'notifications.entity_type_profile',
        'user' => 'reports.entity_user',
        'content' => 'notifications.entity_type_content',
    ];

    /** Human label for a report reason slug; free text passes through, unknown tokens degrade. */
    public static function reason(string $reason, ?string $locale = null): string
    {
        if ($reason === self::REASON_FALLBACK) {
            return __(self::REASON_FALLBACK_KEY, [], $locale);
        }

        if (isset(self::REASON_KEYS[$reason])) {
            return __(self::REASON_KEYS[$reason], [], $locale);
        }

        // Free text (spaces) is admin/user prose — render it; an unknown
        // machine token degrades to the generic "Other" label instead of
        // echoing a slug at a human being.
        return str_contains($reason, ' ')
            ? $reason
            : __(self::REASON_KEYS['other'], [], $locale);
    }

    /** Human label for a reported-entity type slug; unknown tokens degrade to "content". */
    public static function entityType(string $type, ?string $locale = null): string
    {
        if (isset(self::ENTITY_TYPE_KEYS[$type])) {
            return __(self::ENTITY_TYPE_KEYS[$type], [], $locale);
        }

        return __(self::ENTITY_TYPE_KEYS['content'], [], $locale);
    }
}
