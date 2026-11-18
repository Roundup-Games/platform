<?php

namespace Tests\Feature\Notifications;

use App\Enums\BadgeSource;
use App\Models\User;
use App\Notifications\BadgeAwardedNotification;
use App\Notifications\EntityInvitation;
use App\Notifications\TeamInvitation;
use App\Notifications\WeeklyDigest;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\Traits\CreatesGameInstances;
use Tests\Traits\CreatesTeams;

uses(CreatesGameInstances::class, CreatesTeams::class);

/*
|--------------------------------------------------------------------------
| Entity-aware digest rows + tiering (M081/S06/T01)
|--------------------------------------------------------------------------
|
| The digest mail renders one row PER unread notification: each row deep-links
| the entity's NAME — resolved through the same extraction the in-app bell
| rows use (NotificationQueryService::extractEntityName), so the heterogeneous
| payload keys (game_name, team_name, session_name, …) all link — to the
| action_url the in-app payload already carries, and rows lacking a resolvable
| name or action_url degrade to the plain type-label line (never a fabricated
| link). Beyond 10 rows the digest stops listing and shows
| the tier line — the EXISTING notifications.body_weekly_digest_more copy, now
| a link to the notifications page. Pinned alongside: the label resolution
| (data.type → label_digest_*) UnsubscribeTest already pins stays byte-identical
| for degraded rows, and the mail-only channel contract is untouched.
|
| All wording assertions resolve translation keys per K004 — never literal
| lang/ strings.
|
| Anchor assertions use regex, not exact matching: the mail pipeline's
| CssToInlineStyles step inlines the theme's `.inner-body a { word-break:
| break-all; }` into every anchor as a style attribute, so the literal HTML
| is always `<a href="…" style="…">…</a>`.
*/

beforeEach(function () {
    $this->user = User::factory()->create(['preferred_language' => 'en']);
    app()->setLocale('en');
});

/**
 * Seed $count unread EntityInvitation rows, one per minute (oldest first) so
 * the unreadNotifications ->latest() ordering is deterministic: the digest's
 * shown window (biggest-group-first, then collection order) always drops the
 * OLDEST rows — the ones created earliest.
 *
 * The base payload mirrors the REAL EntityInvitation::toDatabase() shape —
 * game_name, NOT entity_name (the unified Entity* classes store their name
 * under the type-prefixed key; the real-payload test below pins the shape
 * against drift). Synthetic fixtures that invented entity_name would mask
 * a name-resolution regression for exactly these classes (M081 review).
 *
 * @param  array<int, array<string, mixed>>  $payloads  Per-row data overrides.
 */
function seedDigestRows(User $user, int $count, array $payloads = []): void
{
    $base = Carbon::parse('2026-11-15 12:00:00');

    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => Str::uuid(),
            'type' => EntityInvitation::class,
            'data' => array_merge([
                'type' => 'game_invitation',
                'game_name' => "Row {$i}",
                'action_url' => url("/digest-target/{$i}"),
            ], $payloads[$i] ?? []),
            'created_at' => $base->copy()->addMinutes($i),
        ];
    }

    $user->notifications()->createMany($rows);
}

function renderDigest(User $user): string
{
    return (string) (WeeklyDigest::fromNotifications($user->unreadNotifications))->toMail($user)->render();
}

/** Regex for an anchor to $url whose text is exactly $text (style attrs tolerated). */
function anchorPattern(string $url, string $text): string
{
    return '/<a href="'.preg_quote($url, '/').'"[^>]*>'.preg_quote(e($text), '/').'<\/a>/';
}

it('links every entity row to its stored action_url', function () {
    seedDigestRows($this->user, 3);

    $rendered = renderDigest($this->user);

    foreach (range(0, 2) as $i) {
        expect($rendered)->toMatch(anchorPattern(url("/digest-target/{$i}"), "Row {$i}"));
    }

    // Under the cap there is no tier line — but the digest's primary CTA
    // button now legitimately anchors the notifications page (M081 fifth
    // pass: the unsubscribe formerly overwrote the single action slot). So:
    // the tier copy is absent, and the index URL appears EXACTLY twice
    // (CTA button + subcopy fallback link) — matched with the closing quote
    // so the unsubscribe-digest footer URL, which shares the /en/notifications
    // prefix, does not satisfy it.
    expect($rendered)
        ->not->toContain(__('notifications.body_weekly_digest_more', ['count' => 0], 'en'))
        ->and(substr_count($rendered, 'href="'.route('notifications.index', ['locale' => 'en']).'"'))
        ->toBe(2);
});

it('shows ten rows and links the tier line to the notifications page beyond that', function () {
    seedDigestRows($this->user, 12);

    $rendered = renderDigest($this->user);

    // Exactly the 10 newest rows link (unreadNotifications is latest()-ordered;
    // the single group keeps that order, so rows 0 and 1 — the two oldest —
    // are the ones beyond the cap).
    foreach (range(2, 11) as $i) {
        expect($rendered)->toMatch(anchorPattern(url("/digest-target/{$i}"), "Row {$i}"));
    }
    foreach ([0, 1] as $i) {
        // Quote-terminated needles: the plain fragment 'digest-target/1'
        // would substring-match the rendered rows 10 and 11.
        expect($rendered)
            ->not->toContain('href="'.url("/digest-target/{$i}").'"')
            ->not->toContain('>Row '.$i.'</a>');
    }

    // The tier line is the existing more-copy, localized by key resolution
    // (K004), and links the notifications page. The exact URL appears three
    // times: tier line + primary CTA button + the subcopy fallback link
    // (the stray-bracket fix made the subcopy's markdown link parse).
    $tierLabel = __('notifications.body_weekly_digest_more', ['count' => 2], 'en');
    $notificationsUrl = route('notifications.index', ['locale' => 'en']);
    expect($rendered)->toMatch(anchorPattern($notificationsUrl, $tierLabel));
    expect(substr_count($rendered, 'href="'.$notificationsUrl.'"'))->toBe(3);
});

it('renders the localized tier copy in both en and de', function (string $locale) {
    $user = $locale === 'de'
        ? User::factory()->create(['preferred_language' => 'de'])
        : $this->user;
    app()->setLocale($locale);

    seedDigestRows($user, 11);

    $rendered = renderDigest($user);

    $tierLabel = __('notifications.body_weekly_digest_more', ['count' => 1], $locale);
    expect($rendered)->toMatch(anchorPattern(route('notifications.index', ['locale' => $locale]), $tierLabel));
    // ...and only the resolved locale's copy — the other locale's literal
    // must not leak into the rendered mail.
    expect($rendered)
        ->not->toContain(e(__('notifications.body_weekly_digest_more', ['count' => 1], $locale === 'de' ? 'en' : 'de')));
})->with(['en', 'de']);

it('degrades rows missing a link payload to the plain type-label line', function () {
    seedDigestRows($this->user, 3, [
        // Row 0: game_name present, action_url absent.
        ['action_url' => null],
        // Row 1: action_url present, game_name absent.
        ['game_name' => null],
        // Row 2: control — both present → links.
        [],
    ]);

    $rendered = renderDigest($this->user);

    // The degraded rows render the data.type label (the exact resolution
    // UnsubscribeTest pins) as a plain line — twice, one per degraded row.
    $label = __('notifications.label_digest_game_invitation', [], 'en');
    expect($rendered)->toContain('• '.$label);
    expect(substr_count($rendered, '• '.$label))->toBe(2);

    // Never a fabricated link: neither degraded row's name appears as anchor
    // text, and the name-less action_url never becomes an href.
    expect($rendered)
        ->not->toContain('>Row 0</a>')
        ->not->toContain('>Row 1</a>')
        ->not->toContain('href="'.url('/digest-target/1'));

    expect($rendered)->toMatch(anchorPattern(url('/digest-target/2'), 'Row 2'));
});

it('links rows whose payload comes from the real EntityInvitation::toDatabase() — game_name, not entity_name', function () {
    // Regression (M081 review): the digest used to read data['entity_name']
    // directly, but the real Entity* payloads store the name under the
    // type-prefixed key (game_name/campaign_name) — every real invitation
    // row degraded to a label-only line while synthetic fixtures kept the
    // tiering tests green. This test seeds the EXACT payload the
    // notification class produces, so a shape change in toDatabase() now
    // fails here instead of silently unlinking the digest.
    $context = $this->createGameWithOwner(['name' => 'Digest Payload Probe']);
    $inviter = User::factory()->create(['profile_complete' => true]);

    $payload = (new EntityInvitation($context['game'], $inviter))->toDatabase($this->user);

    expect($payload)
        ->toHaveKey('game_name', 'Digest Payload Probe')
        ->not->toHaveKey('entity_name');

    $this->user->notifications()->create([
        'id' => Str::uuid(),
        'type' => EntityInvitation::class,
        'data' => $payload,
        'created_at' => Carbon::parse('2026-11-15 12:00:00'),
    ]);

    $rendered = renderDigest($this->user);

    // The real game name deep-links to the payload's own action_url through
    // the shared name extraction (unified-class game_name branch).
    expect($rendered)->toMatch(anchorPattern($payload['action_url'], 'Digest Payload Probe'));
    // And the row did not degrade: the label-only line is absent.
    expect($rendered)->not->toContain('• '.__('notifications.label_digest_game_invitation', [], 'en'));
});

it('links team invitation rows through the TARGET_ENTITY_KEYS name extraction', function () {
    // Second extraction arm: TeamInvitation stores the name under team_name
    // (TARGET_ENTITY_KEYS mapping), not entity_name.
    $context = $this->createTeamWithCaptain(['name' => 'Probe Team']);
    $inviter = User::factory()->create(['profile_complete' => true]);

    $payload = (new TeamInvitation($context['team'], $inviter))->toDatabase($this->user);

    expect($payload)
        ->toHaveKey('team_name', 'Probe Team')
        ->not->toHaveKey('entity_name');

    $this->user->notifications()->create([
        'id' => Str::uuid(),
        'type' => TeamInvitation::class,
        'data' => $payload,
        'created_at' => Carbon::parse('2026-11-15 12:00:00'),
    ]);

    $rendered = renderDigest($this->user);

    expect($rendered)->toMatch(anchorPattern($payload['action_url'], 'Probe Team'));
});

it('links badge award rows through the badge_name extraction arm', function () {
    // BadgeAwardedNotification carries no entity/game/campaign name — the
    // badge display name lives under badge_name, and rows degraded to a
    // verb-only line before the arm existed (M081 review).
    $payload = (new BadgeAwardedNotification('supporting_member', null, BadgeSource::Automatic, false))
        ->toDatabase($this->user);

    expect($payload)
        ->toHaveKey('badge_name')
        ->not->toHaveKey('entity_name');

    $this->user->notifications()->create([
        'id' => Str::uuid(),
        'type' => BadgeAwardedNotification::class,
        'data' => $payload,
        'created_at' => Carbon::parse('2026-11-15 12:00:00'),
    ]);

    $rendered = renderDigest($this->user);

    expect($rendered)->toMatch(anchorPattern($payload['action_url'], $payload['badge_name']));
});
