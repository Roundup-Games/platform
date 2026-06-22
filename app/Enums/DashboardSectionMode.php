<?php

namespace App\Enums;

/**
 * Which Dashboard mode(s) a section renders in.
 *
 * Drives the warm job's mode-conditional section selection.
 */
enum DashboardSectionMode: string
{
    /** Rendered in both newcomer and established modes. */
    case Both = 'both';

    /** Rendered only in newcomer mode. */
    case Newcomer = 'newcomer';

    /** Rendered only in established mode. */
    case Established = 'established';
}
