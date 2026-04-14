<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Translation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'locale',
        'field',
        'value',
    ];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
