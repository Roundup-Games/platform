<?php

namespace App\SEO\Cwv;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin transport + parse layer over the free PageSpeed Insights API.
 * One request per (url, strategy); the response carries the Lighthouse
 * lab score plus CrUX field percentiles where real-user data exists.
 *
 * Returns a normalized result array or null on failure — never throws:
 * monitoring must not take the scheduler down with it.
 */
final class PsiClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function isEnabled(): bool
    {
        $key = config('services.pagespeed.key');

        return is_string($key) && $key !== '';
    }

    /**
     * @return array{performance_score: int|null, lcp_ms: int|null, inp_ms: int|null, cls: float|null, field_data_state: string, payload: array<string, mixed>}|null
     */
    public function check(string $url, string $strategy): ?array
    {
        $key = config('services.pagespeed.key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        try {
            $response = Http::timeout(90)->get(self::ENDPOINT, [
                'url' => $url,
                'strategy' => $strategy,
                'category' => 'performance',
                'key' => $key,
            ]);
        } catch (Throwable $e) {
            Log::warning('cwv.psi_request_failed', ['url' => $url, 'strategy' => $strategy, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('cwv.psi_error', [
                'url' => $url,
                'strategy' => $strategy,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 200),
            ]);

            return null;
        }

        return $this->parse($response, $url, $strategy);
    }

    /**
     * @return array{performance_score: int|null, lcp_ms: int|null, inp_ms: int|null, cls: float|null, field_data_state: string, payload: array<string, mixed>}
     */
    private function parse(Response $response, string $url, string $strategy): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];

        $lighthouseScore = data_get($data, 'lighthouseResult.categories.performance.score');
        $performance = is_numeric($lighthouseScore) ? (int) round(((float) $lighthouseScore) * 100) : null;

        $metrics = data_get($data, 'loadingExperience.metrics');
        $metrics = is_array($metrics) ? $metrics : [];
        $state = data_get($data, 'loadingExperience.overall_category', 'NONE');
        $fieldDataState = is_string($state) ? mb_strtolower($state) : 'none';

        $lcp = $this->fieldMetric($metrics, 'LARGEST_CONTENTFUL_PAINT');
        $inp = $this->fieldMetric($metrics, 'INTERACTION_TO_NEXT_PAINT');
        $cls = $this->fieldMetric($metrics, 'CUMULATIVE_LAYOUT_SHIFT');

        return [
            'performance_score' => $performance,
            'lcp_ms' => is_numeric($lcp) ? (int) $lcp : null,
            'inp_ms' => is_numeric($inp) ? (int) $inp : null,
            'cls' => is_numeric($cls) ? (float) $cls : null,
            'field_data_state' => $fieldDataState,
            'payload' => [
                'url' => $url,
                'strategy' => $strategy,
                'lighthouse' => $performance,
                'field' => [
                    'lcp_ms' => is_numeric($lcp) ? (int) $lcp : null,
                    'inp_ms' => is_numeric($inp) ? (int) $inp : null,
                    'cls' => is_numeric($cls) ? (float) $cls : null,
                    'state' => $fieldDataState,
                ],
            ],
        ];
    }

    /**
     * CrUX percentile for one metric key, null when no field data exists.
     */
    /**
     * @param  array<string, mixed>  $metrics
     */
    private function fieldMetric(array $metrics, string $key): mixed
    {
        $percentile = data_get($metrics, $key.'.percentile');

        return is_numeric($percentile) ? $percentile : null;
    }
}
