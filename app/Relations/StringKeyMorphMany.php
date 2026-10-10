<?php

namespace App\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * MorphMany that string-casts the foreign key values.
 *
 * Historical seam for the mixed varchar(36)-morph-column / integer-PK era:
 * PostgreSQL rejected varchar = integer comparisons, and Eloquent's
 * default addEagerConstraints() uses whereIntegerInRaw for integer keys,
 * which bypasses PDO binding entirely. With native uuid columns and
 * uuid keys everywhere (D170) both concerns are gone; the cast is now a
 * no-op kept for stability — see StringMorphMediaKey for the policy.
 *
 * Key insight: Eloquent's default addEagerConstraints() uses
 * whereInMethod() which returns 'whereIntegerInRaw' for integer-keyed
 * models. That generates raw SQL like WHERE model_id IN (2) without
 * PDO parameter binding, bypassing any PHP-side string casting.
 * We override to force 'whereIn' which uses PDO binding, and cast
 * all keys to string.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
class StringKeyMorphMany extends MorphMany
{
    /**
     * Cast the parent key to string for lazy-loading constraint.
     */
    public function getParentKey()
    {
        $key = parent::getParentKey();

        return $key !== null ? (is_string($key) || is_int($key) ? (string) $key : null) : null;
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
        $pk = $this->getParentKey();
        $child->setAttribute($this->getForeignKeyName(), is_string($pk) || is_int($pk) ? (string) $pk : '');
        $child->setAttribute($this->getMorphType(), $this->morphClass);
    }
}
