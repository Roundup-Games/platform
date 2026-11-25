<?php

namespace App\Dto;

use App\Models\Concerns\HasAgreementLock;
use App\Services\Edit\AgreementEditService;

/**
 * Outcome of one owner or admin edit request routed through
 * {@see AgreementEditService::apply()} — the single gated write path for
 * game and campaign field changes (M084, decisions D313 and D314).
 *
 * A request that is refused leaves this class out of it altogether: a Tier-1
 * change on an agreement-locked entity throws a ValidationException from the
 * service and nothing is written. This result only describes requests that
 * went through — including the ones that turned out to change nothing.
 *
 * Mirrors the style of {@see CapacityChangeResult}.
 */
class EditResult
{
    /**
     * The fields the service actually WROTE, in request order: machine column
     * name => the storage-ready value it was given. Empty when every submitted
     * value already equalled the stored one (a no-op saves nothing, logs
     * nothing and notifies nobody) — and when the request carried only game
     * system keys: those are gated (Tier 1) but never written here, because
     * the pivot sync belongs to the calling edit surface.
     *
     * @param  array<string, mixed>  $applied
     * @param  bool  $wasLocked  Whether the entity was agreement-locked when the request was applied — i.e. whether it has ever had a confirmed non-owner participant ({@see HasAgreementLock::isAgreementLocked()}). Reported for accepted and no-op requests alike, so a caller can keep its lock notice in step without a second query.
     */
    public function __construct(
        public readonly array $applied,
        public readonly bool $wasLocked,
    ) {}

    /**
     * The machine names of the written fields, in request order — the list
     * the activity log and the EntityUpdated notification carry.
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        return array_keys($this->applied);
    }

    /** Whether the request wrote anything. */
    public function isEmpty(): bool
    {
        return $this->applied === [];
    }
}
