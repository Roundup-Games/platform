<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\SessionZeroConfirmationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A session-zero survey confirmation or — pinned to a
 * SessionZeroSurveyVersion — a charter signature (M070/S02,
 * docs/spec/campaign-charter-concept.md §3). Version-pinned rows carry the
 * read attestation + §8 agreement ticks and the §2 private boundary answers
 * (GM-only); legacy rows (null version) keep their original meaning and are
 * never migrated. confirmed_at doubles as the signature timestamp.
 *
 * @property string $user_id
 * @property string|null $session_zero_survey_version_id
 * @property array<string, mixed>|null $attestations
 * @property array<string, mixed>|null $private_answers
 * @property string $status
 * @property string $role
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SessionZeroConfirmation extends Model
{
    public const ROLE_PARTICIPANT = 'participant';

    public const ROLE_GM = 'gm';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_PENDING_REAGREEMENT = 'pending_reagreement';

    /** @use HasFactory<SessionZeroConfirmationFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $table = 'session_zero_confirmations';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'session_zero_survey_id',
        'session_zero_survey_version_id',
        'user_id',
        'confirmed_at',
        'attestations',
        'private_answers',
        'status',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'attestations' => 'array',
            'private_answers' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $confirmation) {
            if (empty($confirmation->confirmed_at)) {
                $confirmation->confirmed_at = now();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /** @return BelongsTo<SessionZeroSurvey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(SessionZeroSurvey::class, 'session_zero_survey_id');
    }

    /**
     * The immutable snapshot this signature pins to (null for legacy
     * survey confirmations).
     *
     * @return BelongsTo<SessionZeroSurveyVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SessionZeroSurveyVersion::class, 'session_zero_survey_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
