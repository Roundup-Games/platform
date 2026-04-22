<?php

namespace App\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * MorphMany that string-casts the foreign key values.
 *
 * Used for the media() relationship where model_id is varchar(36)
 * but parent models may have integer primary keys. PostgreSQL rejects
 * varchar = integer comparisons, so this ensures all key comparisons
 * use string values.
 *
 * Key insight: Eloquent's default addEagerConstraints() uses
 * whereInMethod() which returns 'whereIntegerInRaw' for integer-keyed
 * models. That generates raw SQL like WHERE model_id IN (2) without
 * PDO parameter binding, bypassing any PHP-side string casting.
 * We override to force 'whereIn' which uses PDO binding, and cast
 * all keys to string.
 */
class StringKeyMorphMany extends MorphMany
{
    /**
     * Cast the parent key to string for lazy-loading constraint.
     */
    public function getParentKey()
    {
        $key = parent::getParentKey();

        return $key !== null ? (string) $key : null;
    }

    /**
     * Override eager constraints to force whereIn (not whereIntegerInRaw)
     * and cast all keys to string.
     *
     * The parent implementation uses whereInMethod() which picks
     * whereIntegerInRaw for integer-keyed models, generating raw SQL
     * like WHERE model_id IN (2). We must use whereIn with string-cast
     * values so PostgreSQL gets WHERE model_id IN ('2').
     */
    public function addEagerConstraints(array $models)
    {
        // Build the morph type constraint (same as parent)
        $this->getRelationQuery()->where($this->morphType, $this->morphClass);

        // Cast keys to string and use whereIn (not whereIntegerInRaw)
        $keys = array_map('strval', parent::getKeys($models, $this->localKey));

        $this->whereInEager(
            'whereIn',
            $this->foreignKey,
            $keys,
            $this->getRelationQuery()
        );
    }

    /**
     * Ensure the foreign key is set as string when attaching.
     */
    protected function setForeignAttributesForCreate(Model $child)
    {
        $child->setAttribute($this->getForeignKeyName(), (string) $this->getParentKey());
        $child->setAttribute($this->getMorphType(), $this->morphClass);
    }
}
