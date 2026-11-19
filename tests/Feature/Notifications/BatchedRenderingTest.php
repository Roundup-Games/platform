<?php

use App\Enums\NotificationCategory;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\BatchedNotification;
use App\Services\NotificationQueryService;
use App\Services\Notifications\SignedReadMarker;
use Carbon\Carbon;

/*
 * Per-channel batched rendering (M082/S02/T02): one flushed batch renders
 * ONE canon-clean notification per channel — the grouped in-app row, the
 * verb-first push with the compact actor prefix, the tiered mail digest,
 * and the Discord embed mirroring the push copy (D130).
 *
 * Every copy assertion resolves lang keys (K004 — assert resolved values,
 * never literals) so wording stays a review concern, not a test pin. The
 * clock is frozen (K014) so the push timestamp's derivation from the last
 * buffered activity's occurred_at is deterministic.
 */

/**
 * One buffered event snapshot — the item payload contract documented on
 * BatchedNotification and written by the buffer path (T04). Named
 * distinctly from the sibling fixtures (batchFlushItemPayload,
 * digestFixturePayload, corpusBatchItem) — one global function namespace.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function batchRenderItem(array $overrides = []): array
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
 * LAST item is the batch's newest activity (the push timestamp source).
 *
 * @param  list<string>  $actorNames
 * @return array<int, array<string, mixed>>
 */
function batchRenderItems(array $actorNames, array $overrides = []): array
{
    $items = [];

    foreach ($actorNames as $index => $name) {
        $items[] = batchRenderItem(array_merge($overrides, [
            'actor_id' => sprintf('01900000-0000-7000-8000-%012d', $index + 1),
            'actor_name' => $name,
            'occurred_at' => Carbon::parse('2026-11-19 11:50:00')->addMinutes($index)->toIso8601String(),
        ]));
    }

    return $items;
}

/**
 * The demo burst: five distinct actors joining one game.
 *
 * @return array<int, array<string, mixed>>
 */
function batchRenderDemoBurst(): array
{
    return batchRenderItems(['Sarah', 'Alex', 'Mara', 'Tim', 'Nina']);
}

/**
 * BatchedNotification for the demo burst (participant_joined on a game).
 */
function batchRenderNotification(?array $items = null, array $overrides = []): BatchedNotification
{
    return new BatchedNotification(
        category: $overrides['category'] ?? NotificationCategory::ParticipantJoined,
        entityType: $overrides['entityType'] ?? 'game',
        entityId: $overrides['entityId'] ?? '01900000-0000-7000-8000-0000000000e1',
        entityName: $overrides['entityName'] ?? 'Tuesday Runde',
        actionUrl: $overrides['actionUrl'] ?? 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1',
        items: $items ?? batchRenderDemoBurst(),
    );
}

// ── In-app grouped row ─────────────────────────────────────────────────────

it('renders one grouped in-app row carrying every actor name and the underlying category', function () {
    $host = User::factory()->create();
    $data = batchRenderNotification()->toDatabase($host);

    // The UNDERLYING category rides data.type so bell grouping, counters,
    // and the settings shortcut treat the batched row first-class.
    expect($data['type'])->toBe('participant_joined')
        ->and($data['entity_type'])->toBe('game')
        ->and($data['entity_id'])->toBe('01900000-0000-7000-8000-0000000000e1')
        ->and($data['game_name'])->toBe('Tuesday Runde')
        // The in-app row is the one surface with room for ALL the names —
        // names and ids stay position-aligned for the grouped display's
        // profile links (M082 post-review display follow-up).
        ->and($data['actor_names'])->toBe(['Sarah', 'Alex', 'Mara', 'Tim', 'Nina'])
        ->and($data['actor_ids'])->toBe([
            '01900000-0000-7000-8000-000000000001',
            '01900000-0000-7000-8000-000000000002',
            '01900000-0000-7000-8000-000000000003',
            '01900000-0000-7000-8000-000000000004',
            '01900000-0000-7000-8000-000000000005',
        ])
        ->and($data['action_url'])->toBe('https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1')
        ->and($data['batched'])->toBeTrue()
        ->and($data['batch_count'])->toBe(5);
});

it('keys the entity name by the payload entity type for campaign batches', function () {
    $host = User::factory()->create();

    $data = batchRenderNotification(
        batchRenderItems(['Sarah'], ['entity_type' => 'campaign', 'entity_name' => 'Rise of the Runelords']),
        ['entityType' => 'campaign', 'entityName' => 'Rise of the Runelords'],
    )->toDatabase($host);

    expect($data['type'])->toBe('participant_joined')
        ->and($data['campaign_name'])->toBe('Rise of the Runelords');
});

// ── Push ───────────────────────────────────────────────────────────────────

it('renders a verb-first push body with the two-name prefix plus remaining count', function () {
    app()->setLocale('en');
    $host = User::factory()->create();
    $push = batchRenderNotification()->toPush($host);

    // Five actors → the demo shape "Sarah, Alex +3", resolved per K004.
    expect($push->body)->toBe(__('notifications.push_body_batched_participant_joined_plural', [
        'actors' => 'Sarah, Alex +3',
    ], 'en'))
        ->and($push->title)->toBe(trans_choice('notifications.push_title_batched', 5, [
            'count' => 5,
            'entity' => 'Tuesday Runde',
        ], 'en'))
        // Envelope: shared icon + badge, tag collapse + re-alert semantics,
        // timestamp = the LAST buffered activity's instant, marker landing.
        ->and($push->icon)->toBe(BaseNotification::PUSH_ICON)
        ->and($push->badge)->toBe(BaseNotification::PUSH_BADGE)
        ->and($push->renotify)->toBeTrue()
        ->and($push->timestamp)->toBe(Carbon::parse('2026-11-19T11:54:00+00:00')->getTimestamp())
        ->and($push->url)->toContain('/notifications/read-marker/')
        ->and($push->url)->toContain('signature=');
});

it('collapses the push actor prefix without a count for one and two actors', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    $two = batchRenderNotification(batchRenderItems(['Sarah', 'Alex']))->toPush($host);
    expect($two->body)->toBe(__('notifications.push_body_batched_participant_joined_plural', [
        'actors' => 'Sarah, Alex',
    ], 'en'));

    // One DISTINCT actor (a rejoin inside the window — two items, same
    // actor id and name) selects the singular variant: German verbs must
    // agree („ist beigetreten"), English copies stay identical.
    $rejoin = [
        batchRenderItem(),
        batchRenderItem(['occurred_at' => Carbon::parse('2026-11-19 11:51:00')->toIso8601String()]),
    ];
    $one = batchRenderNotification($rejoin)->toPush($host);
    expect($one->body)->toBe(__('notifications.push_body_batched_participant_joined', [
        'actors' => 'Sarah',
    ], 'en'));
});

it('keeps single-event batches off the interrupt channels — push and Discord render null below the floor', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    // MIN_INTERRUPT_ITEMS (M082 review): one buffered event is the
    // pre-M082 single-event case — in-app row + mail digest only, so the
    // defer window never manufactures interrupts (D286). Discord mirrors
    // push's floor (D130).
    $single = batchRenderNotification(batchRenderItems(['Sarah']));

    expect($single->toPush($host))->toBeNull()
        ->and($single->toDiscord($host))->toBeNull()
        // The informational surfaces render at every batch size.
        ->and($single->toDatabase($host))->toBeArray()
        ->and($single->toMail($host)->introLines)->toBe([
            __('notifications.body_batched_line_participant_joined', ['actor' => 'Sarah'], 'en'),
        ]);

    // At the floor itself the interrupt channels fire.
    $burst = batchRenderNotification(batchRenderItems(['Sarah', 'Alex']));
    expect($burst->toPush($host))->not->toBeNull()
        ->and($burst->toDiscord($host))->not->toBeNull();
});

it('tags the push per batch key so consecutive batches for one entity replace each other', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    $first = batchRenderNotification()->toPush($host);
    $second = batchRenderNotification(batchRenderItems(['Mara', 'Tim', 'Nina']))->toPush($host);

    expect($first->tag)->toBe('batched-participant_joined-game-01900000-0000-7000-8000-0000000000e1')
        ->and($second->tag)->toBe($first->tag)
        // A different entity keys its own batch — no collapse across games.
        ->and(
            batchRenderNotification(batchRenderItems(['Mara', 'Tim']), ['entityId' => '01900000-0000-7000-8000-0000000000e2'])->toPush($host)->tag,
        )->not->toBe($first->tag);
});

it('agrees the German push body with the actor count', function () {
    app()->setLocale('de');
    $host = User::factory()->create();

    // One DISTINCT actor across two buffered events (rejoin inside the
    // window — same actor id, two items) — the singular verb must still
    // agree.
    $rejoin = [
        batchRenderItem(),
        batchRenderItem(['occurred_at' => Carbon::parse('2026-11-19 11:51:00')->toIso8601String()]),
    ];
    $singular = batchRenderNotification($rejoin)->toPush($host);
    $plural = batchRenderNotification()->toPush($host);

    expect($singular->body)->toBe(__('notifications.push_body_batched_participant_joined', [
        'actors' => 'Sarah',
    ], 'de'))
        ->and($plural->body)->toBe(__('notifications.push_body_batched_participant_joined_plural', [
            'actors' => 'Sarah, Alex +3',
        ], 'de'));
});

// ── Mail digest tiers ──────────────────────────────────────────────────────

it('renders one full line per buffered activity for small batches', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    $mail = batchRenderNotification(batchRenderItems(['Sarah', 'Alex', 'Mara']))->toMail($host);

    // Tier boundary (≤ 3 activities): every event gets its own digest line.
    expect($mail->introLines)->toBe([
        __('notifications.body_batched_line_participant_joined', ['actor' => 'Sarah'], 'en'),
        __('notifications.body_batched_line_participant_joined', ['actor' => 'Alex'], 'en'),
        __('notifications.body_batched_line_participant_joined', ['actor' => 'Mara'], 'en'),
    ]);
});

it('collapses larger batches to the headline plus count tier', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    $mail = batchRenderNotification()->toMail($host);

    // Five activities > 3 → one headline line, never five.
    expect($mail->introLines)->toHaveCount(1)
        ->and($mail->introLines[0])->toBe(__('notifications.body_batched_headline_participant_joined_plural', [
            'actors' => 'Sarah, Alex +3',
            'count' => 5,
        ], 'en'));
});

it('uses the singular headline when one actor repeats beyond the tier boundary', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    // Four activities by ONE distinct actor: the tier keys on activities,
    // the verb agreement on actors.
    $mail = batchRenderNotification(array_fill(0, 4, batchRenderItem()))->toMail($host);

    expect($mail->introLines[0])->toBe(__('notifications.body_batched_headline_participant_joined', [
        'actors' => 'Sarah',
        'count' => 4,
    ], 'en'));
});

it('wraps the mail digest in the standard envelope with a marker CTA', function () {
    app()->setLocale('en');
    $host = User::factory()->create();
    $mail = batchRenderNotification()->toMail($host);

    expect($mail->subject)->toBe(trans_choice('notifications.subject_batched', 5, [
        'count' => 5,
        'entity' => 'Tuesday Runde',
    ], 'en'))
        ->and($mail->viewData['preheader'])->toBe(trans_choice('notifications.preheader_batched', 5, [
            'count' => '5',
            'entity' => 'Tuesday Runde',
        ], 'en'));

    $html = (string) $mail->render();

    // Signed unsubscribe targeting the UNDERLYING category + marker-wrapped CTA.
    expect($html)->toContain('signature=')
        ->and($html)->toContain('/notifications/unsubscribe/'.$host->id.'/participant_joined?')
        ->and($html)->toContain('/notifications/read-marker/');
});

it('renders the German mail digest through the same key resolution', function () {
    app()->setLocale('de');
    $host = User::factory()->create();

    $small = batchRenderNotification(batchRenderItems(['Sarah']))->toMail($host);
    expect($small->introLines)->toBe([
        __('notifications.body_batched_line_participant_joined', ['actor' => 'Sarah'], 'de'),
    ]);

    $large = batchRenderNotification()->toMail($host);
    expect($large->introLines[0])->toBe(__('notifications.body_batched_headline_participant_joined_plural', [
        'actors' => 'Sarah, Alex +3',
        'count' => 5,
    ], 'de'))
        ->and($large->subject)->toBe(trans_choice('notifications.subject_batched', 5, [
            'count' => 5,
            'entity' => 'Tuesday Runde',
        ], 'de'));
});

it('pluralizes the count-bearing copy at one — the most common batch is a single buffered event', function (string $locale) {
    // M082 review: batching has no minimum threshold, so a lone join or
    // application flushes as a 1-item batch — "1 update(s)" copy would be
    // grammatically wrong in BOTH locales' subject line. trans_choice
    // resolves the singular arm; assertions stay wording-agnostic (K004).
    app()->setLocale($locale);
    $host = User::factory()->create();

    $mail = batchRenderNotification(batchRenderItems(['Sarah']))->toMail($host);

    expect($mail->subject)->toBe(trans_choice('notifications.subject_batched', 1, [
        'count' => 1,
        'entity' => 'Tuesday Runde',
    ], $locale))
        ->and($mail->viewData['preheader'])->toBe(trans_choice('notifications.preheader_batched', 1, [
            'count' => '1',
            'entity' => 'Tuesday Runde',
        ], $locale));
})->with(['en', 'de']);

it('names the session in each digest line for session bursts — the session is the news', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    // One host scheduling two sessions: actor-only lines would repeat
    // identically, so each line carries the buffered session's name.
    $items = [
        batchRenderItem([
            'entity_type' => 'campaign',
            'entity_id' => '01900000-0000-7000-8000-0000000000c1',
            'entity_name' => 'Rise of the Runelords',
            'event' => 'session_added_to_campaign',
            'context' => ['session_id' => '01900000-0000-7000-8000-0000000000a1', 'session_name' => 'Chapter 1'],
        ]),
        batchRenderItem([
            'entity_type' => 'campaign',
            'entity_id' => '01900000-0000-7000-8000-0000000000c1',
            'entity_name' => 'Rise of the Runelords',
            'event' => 'session_added_to_campaign',
            'context' => ['session_id' => '01900000-0000-7000-8000-0000000000a2', 'session_name' => 'Chapter 2'],
        ]),
    ];

    $notification = batchRenderNotification($items, [
        'category' => NotificationCategory::SessionAddedToCampaign,
        'entityType' => 'campaign',
        'entityId' => '01900000-0000-7000-8000-0000000000c1',
        'entityName' => 'Rise of the Runelords',
    ]);

    $mail = $notification->toMail($host);

    expect($mail->introLines)->toBe([
        __('notifications.body_batched_line_session_added_to_campaign_with_session', [
            'actor' => 'Sarah', 'session' => 'Chapter 1',
        ], 'en'),
        __('notifications.body_batched_line_session_added_to_campaign_with_session', [
            'actor' => 'Sarah', 'session' => 'Chapter 2',
        ], 'en'),
    ]);
});

// ── Discord embed ──────────────────────────────────────────────────────────

it('targets the campaign page on every session-burst surface — the batch is about the campaign, not the first session', function () {
    app()->setLocale('en');
    $member = User::factory()->create();

    // A two-session burst: every item's own action_url points at ITS
    // session's game page (what the per-session ICS attachments deep-link),
    // but the batch-level surfaces name the CAMPAIGN — subject, CTA label
    // "View Campaign", push/Discord title, bell entity link — so they must
    // land on campaigns.detail, not on the first buffered session's page.
    // Recipients are members (not the owner), so the member-visible detail
    // page is the target, never the owner dashboard.
    $items = [
        batchRenderItem([
            'entity_type' => 'campaign',
            'entity_id' => '01900000-0000-7000-8000-0000000000c1',
            'entity_name' => 'Rise of the Runelords',
            'event' => 'session_added_to_campaign',
            'context' => ['session_id' => '01900000-0000-7000-8000-0000000000a1', 'session_name' => 'Chapter 1'],
            'action_url' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000a1',
        ]),
        batchRenderItem([
            'entity_type' => 'campaign',
            'entity_id' => '01900000-0000-7000-8000-0000000000c1',
            'entity_name' => 'Rise of the Runelords',
            'event' => 'session_added_to_campaign',
            'context' => ['session_id' => '01900000-0000-7000-8000-0000000000a2', 'session_name' => 'Chapter 2'],
            'action_url' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000a2',
        ]),
    ];

    $notification = batchRenderNotification($items, [
        'category' => NotificationCategory::SessionAddedToCampaign,
        'entityType' => 'campaign',
        'entityId' => '01900000-0000-7000-8000-0000000000c1',
        'entityName' => 'Rise of the Runelords',
        'actionUrl' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000a1',
    ]);

    $campaignUrl = route('campaigns.detail', [
        'locale' => 'en',
        'id' => '01900000-0000-7000-8000-0000000000c1',
    ]);

    // Bell row: the linked campaign name targets the campaign page.
    expect($notification->toDatabase($member)['action_url'])->toBe($campaignUrl);

    // Push (2 items ≥ the interrupt floor): the tap target is the campaign.
    expect($notification->toPush($member)->url)->toBe(
        app(SignedReadMarker::class)->build(
            $member,
            'en',
            BatchedNotification::class,
            '01900000-0000-7000-8000-0000000000c1',
            'entity_id',
            '/en/campaigns/01900000-0000-7000-8000-0000000000c1',
            'session_added_to_campaign',
        ),
    );

    // Discord: embed url and the author byline both name AND link the campaign.
    $embed = $notification->toDiscord($member)->embeds[0];
    expect($embed['url'])->toBe($campaignUrl)
        ->and($embed['author']['url'])->toBe($campaignUrl);

    // Mail CTA: "View Campaign" lands on the campaign page, wrapped in the
    // signed read marker exactly as the push target is.
    expect($notification->toMail($member)->actionUrl)->toBe(
        app(SignedReadMarker::class)->build(
            $member,
            'en',
            BatchedNotification::class,
            '01900000-0000-7000-8000-0000000000c1',
            'entity_id',
            '/en/campaigns/01900000-0000-7000-8000-0000000000c1',
            'session_added_to_campaign',
        ),
    );
});

it('mirrors the push copy as a Discord embed with the entity as the author line', function () {
    app()->setLocale('en');
    $host = User::factory()->create();

    $payload = batchRenderNotification()->toDiscord($host);
    $embed = $payload->embeds[0];

    $push = batchRenderNotification()->toPush($host);

    expect($embed['title'])->toBe($push->title)
        ->and($embed['description'])->toBe($push->body)
        ->and($embed['url'])->toBe('https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1')
        ->and($embed['author'])->toBe([
            'name' => 'Tuesday Runde',
            'url' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1',
        ])
        // K013: every Discord wire shape carries the mention guard.
        ->and($payload->toArray()['allowed_mentions'])->toBe(['parse' => []]);
});

// ── Canon completeness ─────────────────────────────────────────────────────

it('keeps copy keys canon-complete for every batchable category in both locales', function () {
    foreach (NotificationCategory::cases() as $category) {
        if (! $category->isBatchable()) {
            continue;
        }

        foreach (['en', 'de'] as $locale) {
            foreach ([
                'push_title_batched',
                'subject_batched',
                'preheader_batched',
                "push_body_batched_{$category->value}",
                "push_body_batched_{$category->value}_plural",
                "body_batched_line_{$category->value}",
                "body_batched_headline_{$category->value}",
                "body_batched_headline_{$category->value}_plural",
            ] as $key) {
                $full = "notifications.{$key}";

                expect(__($full, [], $locale))->not->toBe($full, "Missing batched copy key [{$key}] in [{$locale}] — widening the batchable set requires the per-category digest voice in every locale.");
            }
        }
    }

    // The session burst's session-named line variant (the actor-only line
    // stays as the legacy/no-context fallback) exists in both locales.
    foreach (['en', 'de'] as $locale) {
        $full = 'notifications.body_batched_line_session_added_to_campaign_with_session';

        expect(__($full, [], $locale))->not->toBe($full, "Missing batched copy key [body_batched_line_session_added_to_campaign_with_session] in [{$locale}].");
    }
});

it('exposes the shared digest derivation the renderers consume', function () {
    // The renderer and the flush path must present the SAME digest numbers
    // (T01's contract): the push/mail prefix derives from digestVariables,
    // not a private reimplementation.
    $digest = NotificationBatch::digestVariables(batchRenderDemoBurst());

    expect($digest['total_activities'])->toBe(5)
        ->and($digest['total_actors'])->toBe(5)
        ->and(array_column($digest['display_actors'], 'name'))->toBe(['Sarah', 'Alex', 'Mara']);
});

// ── Read-side name extraction ──────────────────────────────────────────────

it('extracts entity names for batched rows through the shared payload resolution', function () {
    $service = app(NotificationQueryService::class);

    $gameRow = batchRenderNotification()->toDatabase(User::factory()->create());
    $campaignRow = batchRenderNotification(
        batchRenderItems(['Sarah'], ['entity_type' => 'campaign', 'entity_name' => 'Rise of the Runelords']),
        ['entityType' => 'campaign', 'entityName' => 'Rise of the Runelords'],
    )->toDatabase(User::factory()->create());

    expect($service->extractEntityName('BatchedNotification', $gameRow))->toBe('Tuesday Runde')
        ->and($service->extractEntityName('BatchedNotification', $campaignRow))->toBe('Rise of the Runelords')
        // Belt for legacy rows: the generic entity_name/game_name/campaign_name
        // fallback still resolves when entity_type is absent.
        ->and($service->extractEntityName('BatchedNotification', ['entity_name' => 'Fallback Name']))->toBe('Fallback Name');
});
