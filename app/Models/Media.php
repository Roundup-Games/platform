<?php

namespace App\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

class Media extends BaseMedia
{
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Override: cast model_id to string so that Eloquent always binds
     * model_id as a string value. The media.model_id column is varchar(36)
     * (changed from bigint to support UUID PKs on Event and other models).
     * PostgreSQL rejects varchar = integer comparisons, so this cast ensures
     * all morph queries produce varchar = varchar comparisons regardless of
     * whether the parent model uses integer or UUID primary keys.
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'model_id' => 'string',
        ]);
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }
}
