<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\BatchedNotification;
use App\Notifications\WeeklyDigest;
use Carbon\Carbon;
use Illuminate\Support\Str;

/*
 * WeeklyDigest interplay with batched rows (M082/S02/T03): a flushed burst
 * lands as ONE in-app row (BatchedNotification, data.type = the UNDERLYING
 * category), and the digest's entity-aware mail rows must treat it
 * first-class — the entity name still deep-links through the SAME shared
 * extraction the bell uses (NotificationQueryService::extractEntityName),
 * and rows whose payload lacks a resolvable name or action_url degrade to
 * the plain label line: the UNDERLYING category's digest label
 * (label_digest_participant_joined), never a 'Batched Notification'
 * basename label.
 *
 * Mirrors WeeklyDigestEntityLinksTest's real-payload pattern: rows are
 * seeded with the EXACT toDatabase() output BatchedNotification produces,
 * so a payload shape change fails here instead of silently unlinking the
 * digest. All wording assertions resolve translation keys (K004 — assert
 * resolved values, never literals). The clock is frozen (K014) so seeded
 * created_at stamps and the digest's latest()-ordering are deterministic.
 */

/**
 * One buffered event snapshot — the item payload contract documented on
 * BatchedNotification. Distinct name from the sibling fixtures
 * (batchRenderItem, batchLocaleItem, batchFlushItemPayload,
 * digestFixturePayload, corpusBatchItem) — one global function namespace.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function batchDigestItem(array $overrides = []): array
{
    return array_merge([
        'actor_id' => '01900000-0000-7000-8000-00000000000a',
        'actor_name' => 'Sarah',
        'entity_type' => 'game',
        'entity_id' => '01900000-0000-7000-8000-0000000000e1',
        'entity_name' => 'Tuesday Runde',
        'event' => 'participant_joined',
        'context' => [],
        'action_url' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1',
        'occurred_at' => '2026-11-19T11:50:00+00:00',
    ], $overrides);
}

/**
 * BatchedNotification for a flushed participant_joined batch on one
 * entity. A single buffered item suffices — the digest reads only the
 * stored toDatabase() payload, never the items.
 */
function batchDigestNotification(
    string $entityType = 'game',
    string $entityName = 'Tuesday Runde',
    string $entityId = '01900000-0000-7000-8000-0000000000e1',
): BatchedNotification {
    $actionUrl = "https://roundup.test/en/{$entityType}s/{$entityId}";

    return new BatchedNotification(
        category: NotificationCategory::ParticipantJoined,
        entityType: $entityType,
        entityId: $entityId,
        entityName: $entityName,
        actionUrl: $actionUrl,
        items: [batchDigestItem([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'action_url' => $actionUrl,
        ])],
    );
}

/**
 * Seed one unread BatchedNotification row carrying $data, stamped minutes
 * before the frozen clock (K014: deterministic latest()-ordering).
 *
 * @param  array<string, mixed>  $data
 */
function batchDigestSeedRow(User $user, array $data, int $minutesAgo = 30): void
{
    $user->notifications()->create([
        'id' => Str::uuid(),
        'type' => BatchedNotification::class,
        'data' => $data,
        'created_at' => now()->subMinutes($minutesAgo),
    ]);
}

/**
 * Render the weekly digest mail for the user's unread rows. Distinct from
 * WeeklyDigestEntityLinksTest's renderDigest() — that helper only exists
 * when its own file is loaded.
 */
function renderBatchedDigest(User $user): string
{
    return (string) WeeklyDigest::fromNotifications($user->unreadNotifications)
        ->toMail($user)
        ->render();
}

/** Regex for an anchor to $url whose text is exactly $text (style attrs tolerated). */
function batchedAnchorPattern(string $url, string $text): string
{
    return '/<a href="'.preg_quote($url, '/').'"[^>]*>'.preg_quote(e($text), '/').'<\/a>/';
}

beforeEach(function () {
    // K014: freeze the clock so the seeded rows' created_at stamps (and the
    // unreadNotifications latest()-ordering they drive) are deterministic.
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
});

it('links a batched in-app row\'s entity name through the shared digest extraction', function (string $locale) {
    $user = User::factory()->create(['preferred_language' => $locale]);
    app()->setLocale($locale);

    // REAL payloads — the exact toDatabase() output a flushed batch stores.
    $gamePayload = batchDigestNotification()->toDatabase($user);
    batchDigestSeedRow($user, $gamePayload, 30);

    $campaignPayload = batchDigestNotification(
        'campaign',
        'Rise of the Runelords',
        '01900000-0000-7000-8000-0000000000c7',
    )->toDatabase($user);
    batchDigestSeedRow($user, $campaignPayload, 20);

    $rendered = renderBatchedDigest($user);

    // Both rows deep-link their entity name — resolved through the same
    // extractEntityName the bell uses (the "{entity_type}_name" dynamic key
    // for batched payloads) — to the action_url the payload carries.
    expect($rendered)->toMatch(batchedAnchorPattern((string) $gamePayload['action_url'], 'Tuesday Runde'))
        ->and($rendered)->toMatch(batchedAnchorPattern((string) $campaignPayload['action_url'], 'Rise of the Runelords'));

    // Neither row degraded: the underlying category's digest label never
    // renders as a plain line while a resolvable name + url exist.
    expect($rendered)->not->toContain('• '.__('notifications.label_digest_participant_joined', [], $locale));
})->with(['en', 'de']);

it('degrades a name-less batched row to the underlying category\'s digest label', function (string $locale) {
    $user = User::factory()->create(['preferred_language' => $locale]);
    app()->setLocale($locale);

    $payload = batchDigestNotification()->toDatabase($user);

    // Arm 1: legacy/corrupt row — no entity_type, no resolvable name key.
    $nameless = $payload;
    unset($nameless['entity_type'], $nameless['game_name'], $nameless['entity_name']);
    batchDigestSeedRow($user, $nameless, 30);

    // Arm 2: the name resolves but action_url is gone — never a fabricated
    // link, degrade to the same label line.
    $urlless = $payload;
    unset($urlless['action_url']);
    batchDigestSeedRow($user, $urlless, 20);

    $rendered = renderBatchedDigest($user);

    // Both arms degrade to the plain label line — the UNDERLYING category's
    // digest label (data.type tier), not a 'Batched Notification' basename —
    // resolved per K004, one line per degraded row.
    $label = __('notifications.label_digest_participant_joined', [], $locale);
    expect($rendered)->toContain('• '.$label)
        ->and(substr_count($rendered, '• '.$label))->toBe(2)
        // Never a fabricated link: the entity name anchors nowhere and the
        // stored action_url never becomes an href.
        ->and($rendered)->not->toContain('>Tuesday Runde</a>')
        ->and($rendered)->not->toContain('href="'.(string) $payload['action_url'].'"');
})->with(['en', 'de']);
