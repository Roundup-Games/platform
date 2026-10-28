<?php

namespace App\Dto;

use App\Services\CapacityService;

/**
 * Outcome of a host-initiated max_players change routed through
 * {@see CapacityService::update()} — the single decision point for the
 * validation rules and the three-way increase / silent-decrease /
 * two-phase-confirm demotion branch previously inlined in
 * GameDetail::updateCapacity().
 *
 * The calling component maps an outcome to Livewire surface effects only
 * (field errors via addError, session flash, confirm-modal arming); every
 * decision — validation, branch routing, flash-message selection — is made
 * by the service, so the component carries no capacity logic. Error and
 * flash payloads are lang KEYS (plus params), not translated strings:
 * translation stays at the presentation edge.
 */
class CapacityUpdateResult
{
    /** Raise applied; the newly-opened slots were auto-filled from the waitlist. */
    public const string INCREASED = 'increased';

    /** Silent decrease applied (newMax still at/above the approved count). */
    public const string DECREASED = 'decreased';

    /** newMax equals the current max — nothing changed. */
    public const string UNCHANGED = 'unchanged';

    /** Decrease below the approved count — arm the two-phase confirm modal. */
    public const string NEEDS_CONFIRMATION = 'needs_confirmation';

    /** Confirmed demotion applied; the displaced LIFO set moved to Waitlisted. */
    public const string DEMOTED = 'demoted';

    /** Refused on terminal game state (completed / attendance resolved). */
    public const string REFUSED = 'refused';

    /** newMax failed validation — field error against capacityNewMax. */
    public const string INVALID_MAX = 'invalid_max';

    /** Demotion reason failed validation — field error against capacityReason. */
    public const string INVALID_REASON = 'invalid_reason';

    /**
     * @param  string  $outcome  One of the outcome constants above.
     * @param  int|null  $newMax  The requested newMax (set on every outcome that carries data; the modal-arming source).
     * @param  int  $promotedCount  Waitlisted players auto-promoted to Pending (INCREASED only).
     * @param  int  $demotedCount  Players demoted to Waitlisted (DEMOTED only).
     * @param  DemotionPreview|null  $preview  Displacement preview (NEEDS_CONFIRMATION only) — the confirm-modal data.
     * @param  string|null  $errorKey  Lang key of the error message (REFUSED, INVALID_MAX, INVALID_REASON).
     * @param  string|null  $flashKey  Lang key of the success flash (INCREASED, DECREASED, DEMOTED).
     * @param  array<string, int|string>  $flashParams  Replacement params for $flashKey.
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?int $newMax = null,
        public readonly int $promotedCount = 0,
        public readonly int $demotedCount = 0,
        public readonly ?DemotionPreview $preview = null,
        public readonly ?string $errorKey = null,
        public readonly ?string $flashKey = null,
        public readonly array $flashParams = [],
    ) {}

    /**
     * The Livewire error-bag field this outcome reports against, if any —
     * lets the component stay free of outcome→field knowledge.
     */
    public function errorField(): ?string
    {
        return match ($this->outcome) {
            self::INVALID_MAX => 'capacityNewMax',
            self::INVALID_REASON => 'capacityReason',
            default => null,
        };
    }

    /** Whether this outcome carries a field-level validation error. */
    public function isFieldError(): bool
    {
        return $this->errorField() !== null;
    }
}
