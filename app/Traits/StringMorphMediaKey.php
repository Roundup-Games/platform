<?php

namespace App\Traits;

use App\Models\Media;
use App\Relations\StringKeyMorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Override Spatie's media() morphMany to use StringKeyMorphMany.
 *
 * media.model_id is a native uuid column (D170) and every media owner is
 * uuid-keyed, so the string-cast seam is no longer load-bearing — it is
 * kept as a defensive no-op (uuid keys are already strings) so media()
 * has a single named implementation instead of a per-model decision.
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
