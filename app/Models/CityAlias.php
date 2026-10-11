<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A confirmed alternative spelling for a city hub (D174): created by the
 * admin merge action (source='merge') — the merged city's name becomes an
 * alias of the surviving hub — or by triage of fuzzy suggestions
 * (source='suggestion'). Location intake resolves free text through
 * CityNormalizer::key against these before provisioning anything new, so
 * a spelling that was once merged never creates a duplicate again.
 *
 * @property string $id
 * @property string $city_id
 * @property string $alias
 * @property string $normalized_key
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CityAlias extends Model
{
    use HasPlatformUuid;

    protected $table = 'city_aliases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'city_id' => 'string',
        ];
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
