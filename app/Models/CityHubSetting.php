<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per city hub setting key (62-04) — the DB layer beneath
 * App\Services\CityHubSettings.
 *
 * String primary key on `key` (e.g. 'min_upcoming_sessions'); no factory —
 * rows are created exclusively through CityHubSettings::set() and read
 * through its cached accessors, so tests never need to fabricate states
 * beyond that surface.
 *
 * @property string $key
 * @property int $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CityHubSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }
}
