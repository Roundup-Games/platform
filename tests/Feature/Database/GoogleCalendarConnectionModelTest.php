<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * Pins the google_calendar_connections storage contract (M083/S02-T01):
 * token encryption at rest, one-row-per-user, cascade lifecycle, and the
 * source_toggles shape — including the coherence seam to S01's consent
 * copy (lang/calendar_sync.php label_source_{source} keys), so the
 * promised sync sources and the stored toggles can never drift apart
 * silently.
 */
describe('google_calendar_connections storage', function () {
    it('encrypts token and refresh_token at rest', function () {
        $connection = GoogleCalendarConnection::factory()->create([
            'token' => 'plain-access-token-value',
            'refresh_token' => 'plain-refresh-token-value',
        ]);

        $stored = DB::table('google_calendar_connections')
            ->where('id', $connection->id)
            ->first();

        // Raw columns are ciphertext: neither equals nor contains the plaintext.
        expect($stored->token)->not->toBe('plain-access-token-value')
            ->and($stored->token)->not->toContain('plain-access-token-value')
            ->and($stored->refresh_token)->not->toBe('plain-refresh-token-value')
            ->and($stored->refresh_token)->not->toContain('plain-refresh-token-value')
            // Round trip: a rehydrated model decrypts transparently.
            ->and($connection->fresh()->token)->toBe('plain-access-token-value')
            ->and($connection->fresh()->refresh_token)->toBe('plain-refresh-token-value');
    });

    it('defaults every source toggle to true', function () {
        // Factory definition omits source_toggles so the jsonb column
        // default fires — the all-true default IS the contract.
        $connection = GoogleCalendarConnection::factory()->create();

        expect($connection->fresh()->source_toggles)->toBe([
            'games' => true,
            'campaign_sessions' => true,
            'events' => true,
        ]);
    });

    it('rejects a second connection for the same user', function () {
        $user = User::factory()->create();
        GoogleCalendarConnection::factory()->for($user)->create();

        expect(fn () => GoogleCalendarConnection::factory()->for($user)->create())
            ->toThrow(QueryException::class, 'google_calendar_connections_user_id_unique');
    });

    it('deletes the connection when the user is deleted', function () {
        $connection = GoogleCalendarConnection::factory()->create();

        $connection->user->delete();

        expect(GoogleCalendarConnection::query()->where('user_id', $connection->user_id)->exists())->toBeFalse();
    });

    it('exposes exactly one connection per user through the User relation', function () {
        $user = User::factory()->create();
        $connection = GoogleCalendarConnection::factory()->for($user)->create();

        expect($user->fresh()->googleCalendarConnection)->toBeInstanceOf(GoogleCalendarConnection::class)
            ->and($user->fresh()->googleCalendarConnection->id)->toBe($connection->id);
    });
});

describe('source_toggles shape validation', function () {
    it('persists a full toggle round trip', function () {
        $connection = GoogleCalendarConnection::factory()->create([
            'source_toggles' => ['games' => true, 'campaign_sessions' => false, 'events' => true],
        ]);

        expect($connection->fresh()->source_toggles)->toBe([
            'games' => true,
            'campaign_sessions' => false,
            'events' => true,
        ]);
    });

    it('rejects a write that drops a contract key', function () {
        expect(fn () => GoogleCalendarConnection::factory()->make([
            'source_toggles' => ['games' => true, 'events' => true],
        ]))->toThrow(InvalidArgumentException::class, 'source_toggles.campaign_sessions');
    });

    it('rejects a write with a non-bool value', function () {
        expect(fn () => GoogleCalendarConnection::factory()->make([
            'source_toggles' => ['games' => 'yes', 'campaign_sessions' => true, 'events' => true],
        ]))->toThrow(InvalidArgumentException::class, 'source_toggles.games must be a bool, got string');
    });

    it('fails loudly reading a corrupted stored shape', function () {
        $connection = GoogleCalendarConnection::factory()->create();

        // Simulate drift: a stored row whose contract key is not a bool.
        DB::table('google_calendar_connections')
            ->where('id', $connection->id)
            ->update(['source_toggles' => '{"games":true,"campaign_sessions":"yes","events":true}']);

        expect(fn () => $connection->fresh()->source_toggles)
            ->toThrow(InvalidArgumentException::class, 'source_toggles.campaign_sessions');
    });

    it('drops unknown extra keys on write so the stored shape stays canonical', function () {
        $connection = GoogleCalendarConnection::factory()->create([
            'source_toggles' => [
                'games' => true, 'campaign_sessions' => true, 'events' => true,
                'surprise' => true,
            ],
        ]);

        $raw = DB::table('google_calendar_connections')->where('id', $connection->id)->value('source_toggles');

        expect(mb_strpos((string) $raw, 'surprise'))->toBeFalse()
            ->and($connection->fresh()->source_toggles)->toBe([
                'games' => true,
                'campaign_sessions' => true,
                'events' => true,
            ]);
    });

    it('enumerates exactly the sources S01 consent copy documents', function () {
        $copySources = collect(Lang::get('calendar_sync', [], 'en'))
            ->keys()
            ->filter(fn (string $key): bool => str_starts_with($key, 'label_source_'))
            ->map(fn (string $key): string => mb_substr($key, mb_strlen('label_source_')))
            ->values()
            ->all();

        // Two-way pin: every stored source has consent-copy documentation,
        // and no documented source is missing from storage. Canonicalizing:
        // the pin is membership, not ordering.
        expect($copySources)->toEqualCanonicalizing(GoogleCalendarConnection::SOURCE_TOGGLES);
    });
});

describe('connection lifecycle helpers', function () {
    it('marks a connection revoked without losing the original revoked_at', function () {
        // Second precision: the column is timestamp(0), so the fixture
        // must round-trip exactly through storage.
        $revokedAt = now()->subDay()->startOfSecond();
        $connection = GoogleCalendarConnection::factory()->revoked()->create([
            'revoked_at' => $revokedAt,
        ]);

        $connection->markRevoked();

        expect($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_REVOKED)
            ->and($connection->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
    });

    it('stamps revoked_at when a live connection is first marked revoked', function () {
        $connection = GoogleCalendarConnection::factory()->create();

        $connection->markRevoked();

        expect($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_REVOKED)
            ->and($connection->fresh()->revoked_at)->not->toBeNull();
    });

    it('stamps last_synced_at on markSynced', function () {
        $connection = GoogleCalendarConnection::factory()->create();

        expect($connection->fresh()->last_synced_at)->toBeNull();

        $connection->markSynced();

        expect($connection->fresh()->last_synced_at)->not->toBeNull();
    });

    it('builds an expired-token state the client layer can refresh from', function () {
        $connection = GoogleCalendarConnection::factory()->expiredToken()->create();

        expect($connection->token_expires_at->isPast())->toBeTrue()
            ->and($connection->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE);
    });
});
