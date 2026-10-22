<?php

namespace App\Models;

use App\Contracts\Participant as ParticipantContract;
use App\Dto\EntityMeta;
use App\Enums\AttendanceStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Concerns\HasParticipantDefaults;
use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\GameParticipantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property JoinSource|null $join_source
 * @property ParticipantRole|null $role
 * @property ParticipantStatus|null $status
 * @property AttendanceStatus|null $attendance_status
 * @property Carbon|null $created_at
 * @property Carbon|null $approved_at
 * @property bool $promoted_manually
 * @property Carbon|null $confirmation_expires_at
 * @property Carbon|null $waitlisted_at
 * @property Carbon|null $attendance_reported_at
 * @property Carbon|null $benched_at
 * @property Carbon|null $removed_at
 */
class GameParticipant extends Pivot implements ParticipantContract
{
    /** @use HasFactory<GameParticipantFactory> */
    use HasFactory;

    use HasParticipantDefaults;
    use HasPlatformUuid;

    protected $table = 'game_participants';

    protected $keyType = 'string';

    protected $fillable = ['id', 'game_id', 'user_id', 'invitee_email', 'role', 'status', 'created_at', 'approved_at', 'promoted_manually', 'attendance_status', 'attendance_reported_by', 'attendance_reported_at', 'attendance_weight', 'attendance_disputed_at', 'confirmation_expires_at', 'waitlisted_at', 'confirmation_attempts', 'benched_at', 'join_source', 'short_link_id', 'removed_by', 'removed_at'];

    protected $casts = [
        'role' => ParticipantRole::class,
        'status' => ParticipantStatus::class,
        'created_at' => 'datetime',
        'approved_at' => 'datetime',
        'promoted_manually' => 'boolean',
        'attendance_status' => AttendanceStatus::class,
        'attendance_reported_at' => 'datetime',
        'attendance_weight' => 'float',
        'attendance_disputed_at' => 'datetime',
        'confirmation_expires_at' => 'datetime',
        'waitlisted_at' => 'datetime',
        'confirmation_attempts' => 'integer',
        'benched_at' => 'datetime',
        'join_source' => JoinSource::class,
        'short_link_id' => 'integer',
        'removed_at' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_reported_by');
    }

    /**
     * @return BelongsTo<ShortLink, $this>
     */
    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }

    /**
     * Resolve entity metadata from a participant instance.
     *
     * Centralizes the repeated instanceof checks used in commands, jobs,
     * and services for logging, locking, and querying.
     */
    public static function entityMeta(): EntityMeta
    {
        return EntityMeta::forGame();
    }

    /**
     * Participant contract — this row belongs to a Game.
     *
     * Pure type information; no database query.
     */
    public function getEntityMeta(): EntityMeta
    {
        return EntityMeta::forGame();
    }

    /**
     * A VERIFIED attendance: an Attended row on a game the organizer
     * resolved (attendance_resolved_at set) that carries a session
     * date. The anti-gaming floor for attendance-driven badges
     * (First Seat, Regulars Table) — single-sourced here so every
     * consumer agrees on what counts (INV-style query-shape rule:
     * never re-implement the clause inline).
     *
     * Joins games — callers ordering by session date use
     * games.date_time, and model hydration needs
     * ->select('game_participants.*') when rows are fetched.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVerifiedAttendance(Builder $query): Builder
    {
        return $query
            ->where('game_participants.attendance_status', AttendanceStatus::Attended->value)
            ->join('games', 'games.id', '=', 'game_participants.game_id')
            ->whereNotNull('games.attendance_resolved_at')
            ->whereNotNull('games.date_time');
    }
}
