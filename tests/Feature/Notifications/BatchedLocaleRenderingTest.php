<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\BatchedNotification;
use Carbon\Carbon;

/*
 * Locale matrix for batched rendering (M082/S02/T03): each channel pinned
 * in EN and DE through key resolution (K004 — assert resolved values, never
 * literals), so wording stays a review concern while the SHAPE (which
 * locale resolves, with which interpolated values) is a test pin.
 *
 * Division of labor with BatchedRenderingTest (T02): that file pins the
 * envelope semantics (tags, tiers, boundaries, marker URLs, agreement
 * selection); this file pins the per-locale resolution matrix — every
 * channel renders the value resolved from THAT locale's notifications
 * file. Key EXISTENCE in both locales is pinned by T02's canon-completeness
 * test; equality alone would not catch a missing DE key (__() silently
 * falls back to EN).
 *
 * The clock is frozen (K014) after the fixture's buffered instants so
 * every now()-derived value — created_at stamps, the occurred_at
 * fallback — is deterministic.
 */

/**
 * One buffered event snapshot — the item payload contract documented on
 * BatchedNotification and written by the buffer path (T04). Named
 * distinctly from the sibling fixtures (batchRenderItem,
 * batchFlushItemPayload, digestFixturePayload, corpusBatchItem) — one
 * global function namespace.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function batchLocaleItem(array $overrides = []): array
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
 * One item per distinct named actor, occurred_at staggered by index so the
 * LAST item is the batch's newest activity (the push timestamp source) and
 * all instants precede the frozen clock (12:00).
 *
 * @param  list<string>  $actorNames
 * @return array<int, array<string, mixed>>
 */
function batchLocaleItems(array $actorNames): array
{
    $items = [];

    foreach ($actorNames as $index => $name) {
        $items[] = batchLocaleItem([
            'actor_id' => sprintf('01900000-0000-7000-8000-%012d', $index + 1),
            'actor_name' => $name,
            'occurred_at' => Carbon::parse('2026-11-19 11:50:00')->addMinutes($index)->toIso8601String(),
        ]);
    }

    return $items;
}

/**
 * BatchedNotification for the demo burst (five distinct actors joining one
 * game) — the same scenario every channel below renders.
 *
 * @param  array<int, array<string, mixed>>|null  $items
 */
function batchLocaleNotification(?array $items = null): BatchedNotification
{
    return new BatchedNotification(
        category: NotificationCategory::ParticipantJoined,
        entityType: 'game',
        entityId: '01900000-0000-7000-8000-0000000000e1',
        entityName: 'Tuesday Runde',
        actionUrl: 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1',
        items: $items ?? batchLocaleItems(['Sarah', 'Alex', 'Mara', 'Tim', 'Nina']),
    );
}

beforeEach(function () {
    // K014: freeze the clock AFTER the fixture's buffered instants
    // (11:50–11:54) so any now() read — created_at stamps, the
    // occurred_at fallback arm of latestOccurredAt() — is deterministic.
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
});

// ── Push ───────────────────────────────────────────────────────────────────

it('renders the push title and body shapes through the active locale', function (string $locale) {
    app()->setLocale($locale);
    $host = User::factory()->create(['preferred_language' => $locale]);

    $push = batchLocaleNotification()->toPush($host);

    // Title: count + entity; body: verb-first with the "Sarah, Alex +3"
    // prefix — both resolved from the ACTIVE locale's file (K004).
    expect($push->title)->toBe(trans_choice('notifications.push_title_batched', 5, [
        'count' => 5,
        'entity' => 'Tuesday Runde',
    ], $locale))
        ->and($push->body)->toBe(__('notifications.push_body_batched_participant_joined_plural', [
            'actors' => 'Sarah, Alex +3',
        ], $locale))
        // K014 pin: the tray timestamp is the LAST buffered activity's
        // instant (11:54), never the frozen render clock (12:00).
        ->and($push->timestamp)->toBe(Carbon::parse('2026-11-19T11:54:00+00:00')->getTimestamp());
})->with(['en', 'de']);

// ── Mail ───────────────────────────────────────────────────────────────────

it('front-loads the entity in the batched mail subject and digest copy', function (string $locale) {
    app()->setLocale($locale);
    $host = User::factory()->create(['preferred_language' => $locale]);

    $mail = batchLocaleNotification()->toMail($host);

    // The subject names the entity (the M081 subject canon — scannable in
    // an inbox list) and resolves from the active locale, as do the
    // headline-tier digest line and the preheader.
    expect($mail->subject)->toBe(trans_choice('notifications.subject_batched', 5, [
        'count' => 5,
        'entity' => 'Tuesday Runde',
    ], $locale))
        ->and($mail->subject)->toContain('Tuesday Runde')
        ->and($mail->introLines)->toHaveCount(1)
        ->and($mail->introLines[0])->toBe(__('notifications.body_batched_headline_participant_joined_plural', [
            'actors' => 'Sarah, Alex +3',
            'count' => 5,
        ], $locale))
        ->and($mail->viewData['preheader'])->toBe(trans_choice('notifications.preheader_batched', 5, [
            'count' => '5',
            'entity' => 'Tuesday Runde',
        ], $locale))
        ->and($mail->viewData['preheader'])->toContain('Tuesday Runde');
})->with(['en', 'de']);

// ── In-app grouped row ─────────────────────────────────────────────────────

it('carries every actor name in the grouped in-app payload regardless of locale', function (string $locale) {
    app()->setLocale($locale);
    $host = User::factory()->create(['preferred_language' => $locale]);

    $data = batchLocaleNotification()->toDatabase($host);

    // The in-app row is the one surface with room for ALL the names. The
    // payload is locale-independent data — the matrix pins that neither
    // locale's render leaks locale-specific copy into it, and that the
    // underlying category keeps the row first-class for bell grouping.
    expect($data['type'])->toBe('participant_joined')
        ->and($data['entity_type'])->toBe('game')
        ->and($data['entity_id'])->toBe('01900000-0000-7000-8000-0000000000e1')
        ->and($data['game_name'])->toBe('Tuesday Runde')
        ->and($data['actor_names'])->toBe(['Sarah', 'Alex', 'Mara', 'Tim', 'Nina'])
        ->and($data['batched'])->toBeTrue()
        ->and($data['batch_count'])->toBe(5)
        ->and($data['action_url'])->toBe('https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1');
})->with(['en', 'de']);

// ── Discord embed ──────────────────────────────────────────────────────────

it('mirrors the localized push copy as the Discord embed description', function (string $locale) {
    app()->setLocale($locale);
    $host = User::factory()->create(['preferred_language' => $locale]);

    $payload = batchLocaleNotification()->toDiscord($host);
    $embed = $payload->embeds[0];

    $push = batchLocaleNotification()->toPush($host);

    // D130: Discord mirrors push — title and description carry the exact
    // push voice resolved from the active locale, the byline names the
    // entity. K013: the wire shape carries the mention guard.
    expect($embed['description'])->toBe($push->body)
        ->and($embed['description'])->toBe(__('notifications.push_body_batched_participant_joined_plural', [
            'actors' => 'Sarah, Alex +3',
        ], $locale))
        ->and($embed['title'])->toBe($push->title)
        ->and($embed['url'])->toBe('https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1')
        ->and($embed['author'])->toBe([
            'name' => 'Tuesday Runde',
            'url' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1',
        ])
        ->and($payload->toArray()['allowed_mentions'])->toBe(['parse' => []]);
})->with(['en', 'de']);
