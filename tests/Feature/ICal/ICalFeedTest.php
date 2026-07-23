<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ICal\ICalFeedRenderer;

/**
 * M057/S05/T03 — per-user tokenized iCal feed (decision D123).
 *
 * The feed reuses the existing ShortLink tokenization (linkable=User,
 * purpose='ical') rather than a new token table. One valid VEVENT per upcoming
 * game the user hosts or is an approved participant in; canceled games emit
 * STATUS:CANCELLED; DTEND = date_time + expected_duration (or all-day when
 * duration is null); locale resolves from the user's preferred_language.
 * Expired/unknown tokens return 404. This is the first-proof test that
 * de-risks the eluceo/ical v2.x API + locale + TimeSpan mapping in isolation.
 */
describe('iCal feed token resolution', function () {
    it('returns 404 for an unknown token code', function () {
        $this->get('/calendar/unknown-code')
            ->assertNotFound();
    });

    it('returns 404 for an expired token', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $user->id,
            'purpose' => 'ical',
            'expires_at' => now()->subDay(),
        ]);

        $this->get("/calendar/{$link->code}")
            ->assertNotFound();
    });

    it('returns 404 for a revoked (soft-deleted) token', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);
        $link->delete();

        $this->get("/calendar/{$link->code}")
            ->assertNotFound();
    });

    it('ignores a non-ical-purpose short link code', function () {
        $user = User::factory()->create();
        // A share-purpose link must NOT grant calendar access.
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $user->id,
            'purpose' => 'share',
        ]);

        $this->get("/calendar/{$link->code}")
            ->assertNotFound();
    });
});

describe('iCal feed — successful rendering', function () {
    it('returns 200 with text/calendar content-type for a valid token', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'expected_duration' => 3,
            'name' => ['en' => 'Dragonlance Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $response = $this->get("/calendar/{$link->code}");

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toStartWith('text/calendar');
    });

    it('emits a valid VEVENT for an upcoming hosted game with correct DTSTART/DTEND', function () {
        $owner = User::factory()->create();
        $start = now()->addDays(7)->setTimeFromTimeString('18:00:00');
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => $start,
            'expected_duration' => 3,
            'name' => ['en' => 'Dragonlance Session'],
            'description' => ['en' => 'An epic adventure'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('BEGIN:VEVENT')
            ->and($body)->toContain('END:VEVENT')
            ->and($body)->toContain('SUMMARY:Dragonlance Session')
            ->and($body)->toContain('DESCRIPTION:An epic adventure')
            ->and($body)->toContain('UID:'.$game->id.'@roundup.games')
            ->and($body)->toContain('STATUS:CONFIRMED');

        // DTSTART present (date portion). The feed stamps the start time.
        $startDate = $start->format('Ymd');
        expect($body)->toContain('DTSTART');
        expect($body)->toContain($startDate);
    });

    it('emits STATUS:CANCELLED for a canceled game', function () {
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'status' => GameStatus::Canceled->value,
            'name' => ['en' => 'Canceled Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('STATUS:CANCELLED');
    });

    it('falls back to all-day when expected_duration is zero', function () {
        // The games.expected_duration column is NOT NULL; the renderer treats
        // a non-positive duration as an all-day event (no timed DTEND).
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'expected_duration' => 0,
            'name' => ['en' => 'All Day Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        // All-day events use DTSTART with a VALUE=DATE and no DTEND/TimedSpan.
        expect($body)->toContain('BEGIN:VEVENT');
    });

    it('includes a game the user is an approved participant in (not hosting)', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(10),
            'name' => ['en' => 'Player Session'],
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $player->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('SUMMARY:Player Session');
    });

    it('excludes games where the user is only a non-approved participant', function () {
        $host = User::factory()->create();
        $pending = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(10),
            'name' => ['en' => 'Pending Only Session'],
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $pending->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $pending->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->not->toContain('Pending Only Session');
    });

    it('excludes past games', function () {
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->subDays(3),
            'name' => ['en' => 'Past Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->not->toContain('Past Session');
    });
});

describe('iCal feed locale resolution', function () {
    it('resolves the summary to the user preferred language when present', function () {
        $owner = User::factory()->create(['preferred_language' => 'de']);
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'name' => ['en' => 'English Title', 'de' => 'German Title'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('SUMMARY:German Title');
    });

    it('falls back to the first available locale when user has no preferred language', function () {
        $owner = User::factory()->create(['preferred_language' => null]);
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'name' => ['en' => 'English Title'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('SUMMARY:English Title');
    });
});

describe('ICalFeedRenderer (isolated unit)', function () {
    it('produces a well-formed calendar envelope with PRODID', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'expected_duration' => 2,
            'name' => ['en' => 'Unit Test Game'],
        ]);

        $output = app(ICalFeedRenderer::class)->render(
            collect([$game]),
            'en',
            'test-roundup',
        );

        expect($output)->toContain('BEGIN:VCALENDAR')
            ->and($output)->toContain('END:VCALENDAR')
            ->and($output)->toContain('PRODID:test-roundup')
            ->and($output)->toContain('BEGIN:VEVENT')
            ->and($output)->toContain('SUMMARY:Unit Test Game');
    });
});
