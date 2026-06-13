<?php

namespace App\Traits;

use App\Models\Media;
use App\Relations\StringKeyMorphMany;
use Illuminate\Database\Eloquent\Model;
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
    /**
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        /** @var class-string<Model> $mediaClass */
        $mediaClass = $this->getMediaModel();
        $instance = $this->newRelatedInstance($mediaClass);

        [$type, $id] = $this->getMorphs('model', '', '');

        /** @var StringKeyMorphMany<Media, $this> */
        return new StringKeyMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn($type),
            $instance->qualifyColumn($id),
            $this->getKeyName(),
        );
    }
}
