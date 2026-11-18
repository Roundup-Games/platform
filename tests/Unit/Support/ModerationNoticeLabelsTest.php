<?php

use App\Support\ModerationNoticeLabels;

// Guards the moderation-mail copy leak (M081 follow-up audit finding #1):
// report-picker slugs and entity-type slugs must never render raw — a
// suspended user must read "Grund: Unangemessener Inhalt", not
// "Grund: inappropriate-content".

describe('ModerationNoticeLabels', function () {
    it('maps every report-picker slug to a human label', function (string $slug, string $key) {
        app()->setLocale('en');

        expect(ModerationNoticeLabels::reason($slug))->toBe(__($key))
            ->and(ModerationNoticeLabels::reason($slug))->not->toBe($slug);
    })->with([
        ['inappropriate-content', 'reports.field_reason_inappropriate_content'],
        ['harassment', 'reports.field_reason_harassment'],
        ['spam', 'reports.field_reason_spam'],
        ['misleading', 'reports.field_reason_misleading'],
        ['other', 'common.content_other'],
    ]);

    it('maps the admin fallback reason to a human label', function () {
        app()->setLocale('en');

        $label = ModerationNoticeLabels::reason('community guidelines violation');

        expect($label)->toBe(__('notifications.field_reason_community_guidelines'))
            ->and($label)->not->toBe('community guidelines violation');
    });

    it('resolves labels in the requested locale', function () {
        expect(ModerationNoticeLabels::reason('inappropriate-content', 'de'))
            ->toBe(__('reports.field_reason_inappropriate_content', [], 'de'));
    });

    it('passes genuine free text through untouched', function () {
        app()->setLocale('en');

        expect(ModerationNoticeLabels::reason('Repeated off-topic posts after two warnings'))
            ->toBe('Repeated off-topic posts after two warnings');
    });

    it('degrades unknown machine tokens to the generic Other label — never the raw slug', function () {
        app()->setLocale('en');

        $label = ModerationNoticeLabels::reason('some-future-reason');

        expect($label)->toBe(__('common.content_other'))
            ->and($label)->not->toContain('some-future-reason');
    });

    it('maps entity type slugs to human labels and degrades unknown ones', function (string $slug, string $key) {
        app()->setLocale('en');

        expect(ModerationNoticeLabels::entityType($slug))->toBe(__($key));
    })->with([
        ['game', 'notifications.entity_type_game'],
        ['campaign', 'notifications.entity_type_campaign'],
        ['profile', 'notifications.entity_type_profile'],
        ['content', 'notifications.entity_type_content'],
        ['user', 'reports.entity_user'],
    ]);

    it('never echoes an unknown entity type slug', function () {
        app()->setLocale('en');

        $label = ModerationNoticeLabels::entityType('future-type');

        expect($label)->toBe(__('notifications.entity_type_content'))
            ->and($label)->not->toContain('future-type');
    });
});
