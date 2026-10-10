<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * @property string $id
 */
class Media extends BaseMedia
{
    use HasPlatformUuid;

    public $incrementing = false;

    protected $keyType = 'string';
}
