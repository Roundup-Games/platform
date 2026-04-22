<?php

namespace App\Traits;

use App\Relations\StringKeyMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Override Spatie's media() morphMany to use StringKeyMorphMany.
 *
 * The media.model_id column is varchar(36) to support UUID PKs on
 * Event and other models. PostgreSQL rejects varchar = integer comparisons,
 * so integer PKs must be cast to string in morph queries.
 *
 * Use this trait alongside Spatie\MediaLibrary\InteractsWithMedia on
 * any model. It is harmless for UUID-keyed models and essential for
 * integer-keyed ones.
 */
trait StringMorphMediaKey
{
    public function media(): MorphMany
    {
        $instance = $this->newRelatedInstance($this->getMediaModel());

        [$type, $id] = $this->getMorphs('model', null, null);

        return new StringKeyMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn($type),
            $instance->qualifyColumn($id),
            $this->getKeyName(),
        );
    }
}
