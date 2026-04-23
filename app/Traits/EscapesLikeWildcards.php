<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Escape SQL LIKE wildcard characters (%, _) in user-provided search strings.
 *
 * Usage:  ->where('name', $this->likeOperator(), '%' . $this->escapeLikeWildcards($this->search) . '%')
 */
trait EscapesLikeWildcards
{
    public function escapeLikeWildcards(string $search): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $search,
        );
    }

    /**
     * Return the case-insensitive LIKE operator for the current database driver.
     * PostgreSQL requires 'ilike' for case-insensitive matching; MySQL's 'like' is already case-insensitive.
     */
    public function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
