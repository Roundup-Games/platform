<?php

namespace App\Enums;

/**
 * How a Dashboard section's cache key is constructed.
 *
 * The cache module switches on this to build read keys, lock keys, tracking
 * sets, and invalidation fan-out. Owning key topology here — rather than
 * re-deriving it in every getter and invalidator — is the locality win that
 * collapses the duplicated 11-branch invalidation chains.
 */
enum KeyShape: string
{
    /** Key: dashboard:{section}:{userId} */
    case Single = 'single';

    /** Key: dashboard:{section}:{userId}:{startOfWeek Y-m-d} */
    case WeekDate = 'week_date';

    /** Key: dashboard:{section}:{userId}:{geohash4} + tracking set dashboard:{section}:keys:{userId} */
    case GeohashTracked = 'geohash_tracked';
}
