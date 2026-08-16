<?php

namespace App\Exceptions;

use App\Services\BggSyncService;

/**
 * Internal control-flow exception: a BGG sync lock hold reached its remote-op
 * deadline and must stop before starting further remote work.
 *
 * Thrown by BggSyncService's remote-op guard, caught only at the slice item
 * loop boundary — never surfaces to callers. The interrupted item is retried
 * under a fresh lock hold (upserts are idempotent).
 *
 * @see BggSyncService
 */
final class BggSyncSliceDeadlineReached extends \RuntimeException {}
