<?php

use App\Models\CwvMeasurement;
use Illuminate\Support\Facades\Http;
use Tests\Traits\CapturesLogRecords;

use function Pest\Laravel\artisan;

//
// cwv:check — Core Web Vitals monitoring over the PageSpeed Insights API
// (SEO track 2026-10-15). Semantics under test:
//   1. No PSI key configured → no-op success, nothing stored.
//   2. Checks persist one append-only row per template × locale × strategy
//      with the parsed Lighthouse score and CrUX field percentiles.
//   3. Any threshold breach stores the row AND logs a structured
//      cwv.degraded line; clean results log nothing.
//   4. API failures are logged and skipped — the run never fails.
//

function psiPayload(float $score, int $lcpMs, int $inpMs, float $cls): array
{
    return [
        'lighthouseResult' => [
            'categories' => ['performance' => ['score' => $score]],
        ],
        'loadingExperience' => [
            'overall_category' => 'FAST',
            'metrics' => [
                'LARGEST_CONTENTFUL_PAINT' => ['percentile' => $lcpMs, 'category' => 'FAST'],
                'INTERACTION_TO_NEXT_PAINT' => ['percentile' => $inpMs, 'category' => 'FAST'],
                'CUMULATIVE_LAYOUT_SHIFT' => ['percentile' => $cls, 'category' => 'FAST'],
            ],
        ],
    ];
}

beforeEach(function () {
    config(['services.pagespeed.key' => 'test-key']);
    config(['cwv.locales' => ['en']]);
    config(['cwv.strategies' => ['mobile']]);
});

uses(CapturesLogRecords::class);

it('stores measurements for a clean template run', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(psiPayload(0.92, 1800, 120, 0.05))]);
    $this->captureLogRecords();

    artisan('cwv:check', ['--template' => ['home']])->assertSuccessful();

    $row = CwvMeasurement::query()->sole();

    expect($row->template)->toBe('home')
        ->and($row->url)->toBe(rtrim(config('app.url'), '/').'/en/')
        ->and($row->strategy)->toBe('mobile')
        ->and($row->performance_score)->toBe(92)
        ->and($row->lcp_ms)->toBe(1800)
        ->and($row->inp_ms)->toBe(120)
        ->and($row->cls)->toBe(0.05)
        ->and($row->fetched_at)->not->toBeNull()
        ->and($this->logRecord('cwv.degraded'))->toBeNull();
});

it('logs a structured degraded line on threshold breaches but still stores the row', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(psiPayload(0.55, 4000, 300, 0.25))]);
    $this->captureLogRecords();

    artisan('cwv:check', ['--template' => ['home']])->assertSuccessful();

    expect(CwvMeasurement::query()->count())->toBe(1);

    $degraded = $this->logRecord('cwv.degraded');
    expect($degraded)->not->toBeNull()
        ->and($degraded['context']['breaches'])->toContain('performance 55<80', 'lcp 4000ms>2500ms');
});

it('skips api failures without failing the run', function () {
    Http::fake(['www.googleapis.com/*' => Http::response('quota exceeded', 429)]);

    artisan('cwv:check', ['--template' => ['home']])->assertSuccessful();

    expect(CwvMeasurement::query()->count())->toBe(0);
});

it('is a no-op without a psi key', function () {
    config(['services.pagespeed.key' => null]);
    Http::fake();

    artisan('cwv:check')->assertSuccessful();

    Http::assertNothingSent();
    expect(CwvMeasurement::query()->count())->toBe(0);
});
