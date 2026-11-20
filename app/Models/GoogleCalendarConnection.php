<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\GoogleCalendarConnectionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A user's Google Calendar managed-sync connection (M083/S02, D287).
 *
 * ONE ROW PER USER (unique on user_id): reconnect (S02-T02) replaces the
 * row, so this model is always the user's current live consent. Tokens
 * are encrypted at rest — the refresh token is the only long-lived Google
 * credential stored, and lang/calendar_sync.php content_scope_storage
 * promises exactly that narrow storage.
 *
 * @property string $id
 * @property string $user_id
 * @property string $google_account_email
 * @property string $token
 * @property string $refresh_token
 * @property Carbon $token_expires_at
 * @property array{games: bool, campaign_sessions: bool, events: bool} $source_toggles
 * @property string $status
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GoogleCalendarConnection extends Model
{
    /**
     * The sync sources a connection can mirror — exactly the enumeration
     * S01's consent copy documents (lang/calendar_sync.php
     * label_source_{source} keys). Pinned together with the lang file in
     * GoogleCalendarConnectionModelTest so the copy and the storage
     * contract can never drift apart silently.
     *
     * @var list<string>
     */
    public const SOURCE_TOGGLES = ['games', 'campaign_sessions', 'events'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * Remove-disconnect in flight (M083 post-review): the user chose to
     * have their Roundup events purged, the purge job owns the calendar
     * until it finishes (purge → revoke → row delete). The Settings
     * section renders the honest "Disconnecting…" state while it holds,
     * and the job aborts if a reconnect reactivated the row first.
     */
    public const STATUS_DISCONNECTING = 'disconnecting';

    /** @use HasFactory<GoogleCalendarConnectionFactory> */
    use HasFactory;

    use HasPlatformUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'google_account_email',
        'token', 'refresh_token', 'token_expires_at',
        'source_toggles', 'status', 'revoked_at', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Per-source sync toggles, held to the canonical shape on every read
     * and write: every SOURCE_TOGGLES key must be present with a bool
     * value — anything else throws, because a drifted row must fail
     * loudly instead of silently syncing (or skipping) a source the
     * consent copy promises to mirror. Unknown extra keys are dropped on
     * write, so the stored shape is always exactly the enumeration.
     *
     * Encode/decode is owned here (not via the 'array' cast): a marked
     * accessor receives the raw attribute, so both directions are pinned
     * in one place.
     *
     * @return Attribute<array{games: bool, campaign_sessions: bool, events: bool}, mixed>
     */
    protected function sourceToggles(): Attribute
    {
        return Attribute::make(
            get: fn ($value): array => $this->validateSourceToggles(
                is_string($value) ? json_decode($value, true) : $value
            ),
            set: fn ($value): array => [
                'source_toggles' => json_encode(
                    $this->validateSourceToggles(
                        is_string($value) ? json_decode($value, true) : $value
                    ),
                    JSON_THROW_ON_ERROR
                ),
            ],
        );
    }

    /**
     * @return array{games: bool, campaign_sessions: bool, events: bool}
     */
    private function validateSourceToggles(mixed $value): array
    {
        $toggles = [];

        foreach (self::SOURCE_TOGGLES as $source) {
            $enabled = is_array($value) ? ($value[$source] ?? null) : null;

            if (! is_bool($enabled)) {
                throw new InvalidArgumentException(
                    "source_toggles.{$source} must be a bool, got ".get_debug_type($enabled).'.'
                );
            }

            $toggles[$source] = $enabled;
        }

        return $toggles;
    }

    /**
     * The source_toggles key an entity belongs to — the same enumeration
     * S01's consent copy documents (SOURCE_TOGGLES): registered events,
     * campaign sessions (a game with a campaign), and standalone games.
     *
     * One definition shared by the dispatch gate (CalendarSyncDispatcher)
     * and the operation-layer gate (GoogleCalendarSyncService) so the two
     * gates can never drift on which toggle governs an entity.
     */
    public static function sourceForEntity(Game|Event $entity): string
    {
        if ($entity instanceof Event) {
            return 'events';
        }

        return $entity->campaign_id !== null ? 'campaign_sessions' : 'games';
    }

    /**
     * Mark the connection revoked (S02-T04's revocation translator and
     * S02-T03's disconnect flow): the surfaced status every calendar
     * surface reads. Idempotent — re-marking keeps the original
     * revoked_at.
     */
    public function markRevoked(): void
    {
        $this->fill([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => $this->revoked_at ?? now(),
        ])->save();
    }

    /**
     * Stamp a completed sync pass (S03's reconcile loop): the "last sync"
     * the Settings status row renders via format_date (K002).
     */
    public function markSynced(): void
    {
        $this->fill(['last_synced_at' => now()])->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
