<?php

namespace App\Traits;

/**
 * Escape SQL LIKE wildcard characters (%, _) in user-provided search strings.
 *
 * Usage:  ->where('name', 'like', '%' . $this->escapeLikeWildcards($this->search) . '%')
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
}
