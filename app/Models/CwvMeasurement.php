<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Carbon\CarbonInterface;
use Database\Factories\CwvMeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One Core Web Vitals measurement run from the PageSpeed Insights API for
 * a (template, url, strategy) tuple. Rows are append-only — trend history
 * is the point, latest-per-template is derived by fetched_at.
 *
 * @property CarbonInterface $fetched_at
 */
class CwvMeasurement extends Model
{
    /** @use HasFactory<CwvMeasurementFactory> */
    use HasFactory, HasPlatformUuid;

    protected $fillable = [
        'template',
        'url',
        'strategy',
        'performance_score',
        'lcp_ms',
        'inp_ms',
        'cls',
        'field_data_state',
        'payload',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'performance_score' => 'integer',
            'lcp_ms' => 'integer',
            'inp_ms' => 'integer',
            'cls' => 'float',
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
