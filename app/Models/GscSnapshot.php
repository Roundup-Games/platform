<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * One Google Search Console digest snapshot (rolling 28 days, plus the
 * previous 28-day window for deltas). Append-only trend rows produced by
 * the weekly seo:gsc-digest command.
 */
class GscSnapshot extends Model
{
    use HasPlatformUuid;

    protected $fillable = [
        'range_start',
        'range_end',
        'clicks',
        'impressions',
        'ctr',
        'position',
        'previous_clicks',
        'previous_impressions',
        'top_queries',
        'top_pages',
    ];

    protected function casts(): array
    {
        return [
            'range_start' => 'date',
            'range_end' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'float',
            'position' => 'float',
            'previous_clicks' => 'integer',
            'previous_impressions' => 'integer',
            'top_queries' => 'array',
            'top_pages' => 'array',
        ];
    }
}
