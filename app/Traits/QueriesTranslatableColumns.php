<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Add locale-scoped WHERE clauses for spatie-translatable JSON columns.
 *
 * After converting text columns to JSONB via spatie/laravel-translatable,
 * plain LIKE/ILIKE no longer works. This trait generates PostgreSQL
 * JSON-path queries like:  WHERE field->>'en' ILIKE '%term%'
 *
 * PostgreSQL-specific: uses ->> JSONB extraction and ILIKE operators.
 * Not compatible with SQLite or MySQL.
 */
trait QueriesTranslatableColumns
{
    /**
     * Columns known to be translatable JSONB. Used as a safety whitelist —
     * only these column names are allowed in whereTranslatableLike to
     * prevent accidental interpolation of user-supplied names into raw SQL.
     *
     * @var string[]
     */
    protected array $knownTranslatableColumns = [
        'name',
        'description',
        'short_description',
        'title',
        'content',
    ];

    /**
     * Add a WHERE clause for a translatable JSON column using locale-scoped search.
     * Generates: WHERE field->>'en' ILIKE '%term%'
     *
     * Handles LIKE wildcard escaping internally. Callers should pass the raw
     * (unescaped) search term — do NOT pre-escape with escapeLikeWildcards().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $field
     */
    protected function whereTranslatableLike(Builder $query, string $field, string $search): void
    {
        $this->assertTranslatableColumn($field);

        $locale = app()->getLocale();
        $escaped = $this->escapeTranslatableLike($search);
        /** @var literal-string $sql */
        $sql = "{$field}->>? ILIKE ?";
        $query->whereRaw($sql, [$locale, "%{$escaped}%"]);
    }

    /**
     * Add an OR WHERE clause for a translatable JSON column using locale-scoped search.
     * Generates: OR WHERE field->>'en' ILIKE '%term%'
     *
     * Handles LIKE wildcard escaping internally. Callers should pass the raw
     * (unescaped) search term — do NOT pre-escape with escapeLikeWildcards().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $field
     */
    protected function orWhereTranslatableLike(Builder $query, string $field, string $search): void
    {
        $this->assertTranslatableColumn($field);

        $locale = app()->getLocale();
        $escaped = $this->escapeTranslatableLike($search);
        /** @var literal-string $sql */
        $sql = "{$field}->>? ILIKE ?";
        $query->orWhereRaw($sql, [$locale, "%{$escaped}%"]);
    }

    /**
     * Escape LIKE wildcard characters in a search string.
     * Order matters: backslash first, then %, then _.
     */
    protected function escapeTranslatableLike(string $search): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $search,
        );
    }

    /**
     * Validate that the given field is a known translatable column.
     * Prevents accidental interpolation of user-supplied column names into raw SQL.
     */
    protected function assertTranslatableColumn(string $field): void
    {
        if (! in_array($field, $this->knownTranslatableColumns, true)) {
            throw new \InvalidArgumentException(
                "Column '{$field}' is not a known translatable column. ".
                'Add it to $knownTranslatableColumns if it should be queryable.'
            );
        }
    }
}
