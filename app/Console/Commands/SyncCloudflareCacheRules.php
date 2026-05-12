<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class SyncCloudflareCacheRules extends Command
{
    protected $signature = 'cloudflare:cache-rules
                            {--dry-run : Show what would change without applying}
                            {--force : Apply even if no changes detected}';

    protected $description = 'Sync Cloudflare Cache Rules from public Laravel routes';

    /** Rule description prefix — identifies managed rules */
    protected const RULE_PREFIX = '[roundup-auto]';

    /** The rulesets phase for cache settings */
    protected const CACHE_PHASE = 'http_request_cache_settings';

    public function handle(): int
    {
        $zoneId = config('services.cloudflare.zone_id');
        $apiToken = config('services.cloudflare.api_token');
        $locales = config('app.available_locales', ['en', 'de']);

        $publicPaths = $this->resolvePublicPaths($locales);
        $this->info('Public path patterns: ' . count($publicPaths));

        $desiredRules = $this->buildDesiredRules($publicPaths);

        if ($this->option('dry-run')) {
            $this->showDryRun($desiredRules);
            return self::SUCCESS;
        }

        if (! $zoneId || ! $apiToken) {
            $this->error('Missing Cloudflare credentials. Set CF_ZONE_ID and CF_API_TOKEN in .env');
            $this->line('');
            $this->line('The API token needs these permissions:');
            $this->line('  • Zone → Cache Rules → Edit');
            $this->line('  • Zone → Cache Purge → Purge');
            return self::FAILURE;
        }

        $client = new CloudflareClient($zoneId, $apiToken);

        // Fetch current rules
        $this->info('Fetching current Cloudflare cache rules...');
        $ruleset = $client->getCacheRuleset();
        $existingRules = $ruleset['rules'] ?? [];
        $rulesetId = $ruleset['id'];
        $this->line("  Ruleset: {$rulesetId}");
        $this->line('  Existing rules: ' . count($existingRules));

        // Separate managed vs manual rules
        $managedRules = array_filter($existingRules, fn ($r) =>
            str_starts_with($r['description'] ?? '', self::RULE_PREFIX)
        );
        $manualRules = array_filter($existingRules, fn ($r) =>
            ! str_starts_with($r['description'] ?? '', self::RULE_PREFIX)
        );
        $this->line('  Managed (auto): ' . count($managedRules));
        $this->line('  Manual (preserved): ' . count($manualRules));

        // Build API-formatted managed rules
        $managedApiRules = array_map(fn ($r) => $this->formatRuleForApi($r), $desiredRules);

        // Check if update is needed
        if (! $this->option('force') && $this->isInSync($managedRules, $desiredRules)) {
            $this->info('No changes needed — cache rules are in sync.');
            return self::SUCCESS;
        }

        // Merge: manual rules first (preserved), then managed rules
        $allRules = array_values(array_merge(
            array_values($manualRules),
            $managedApiRules,
        ));

        $this->info('Applying ' . count($managedApiRules) . ' managed cache rules...');
        foreach ($desiredRules as $rule) {
            $this->line("  • {$rule['description']}");
        }

        $client->putCacheRules($rulesetId, $allRules);

        // Purge cache so new rules take effect immediately
        $this->info('Purging Cloudflare cache...');
        $client->purgeEverything();

        $this->info('Cache rules synced successfully.');
        return self::SUCCESS;
    }

    // ── Route Resolution ───────────────────────────────────────────────────

    /**
     * Resolve public paths by scanning Laravel routes.
     *
     * Returns grouped path info used to build the Cloudflare filter expression.
     *
     * @param  string[]  $locales
     * @return array{exact: string[], prefixes: string[]}
     */
    protected function resolvePublicPaths(array $locales): array
    {
        $exactPaths = [];
        $prefixPaths = [];

        foreach ($locales as $locale) {
            $exactPaths["/{$locale}"] = true;
            $exactPaths["/{$locale}/"] = true;
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods())) {
                continue;
            }

            $uri = $route->uri();
            if (! str_starts_with($uri, '{locale}')) {
                continue;
            }

            if ($this->isAuthGated($route->gatherMiddleware())) {
                continue;
            }

            $name = $route->getName() ?? '';
            if ($this->isSkippable($name)) {
                continue;
            }

            // Path after locale prefix, e.g. "game-systems/{slug}"
            $pathAfterLocale = preg_replace('#^\{locale\}/?#', '', $uri);

            // Check if this route has parameters
            $hasParams = preg_match('#\{[^}]+\}#', $pathAfterLocale) === 1;

            // Get the full path segments
            $segments = explode('/', $pathAfterLocale);
            $firstSegment = $segments[0] ?? '';

            // Root-level parameter like {locale}/{id} — just a locale path
            if ($firstSegment && preg_match('/^\{.*\}$/', $firstSegment)) {
                continue;
            }

            foreach ($locales as $locale) {
                if ($hasParams && $firstSegment) {
                    // Dynamic route: use prefix match
                    $prefixPaths["/{$locale}/{$firstSegment}"] = true;
                } elseif ($firstSegment) {
                    // Static route: exact match
                    $exactPaths["/{$locale}/{$firstSegment}"] = true;
                }
            }
        }

        return [
            'exact' => array_keys($exactPaths),
            'prefixes' => array_keys($prefixPaths),
        ];
    }

    protected function isAuthGated(array $middleware): bool
    {
        return ! empty(array_intersect($middleware, [
            'auth', 'auth:api', 'verified', 'profile.complete', 'auth.session',
        ]));
    }

    protected function isSkippable(string $name): bool
    {
        foreach (['sanctum.', 'livewire.', 'storage.', 'oauth.', 'locale.',
                     'password.', 'verification.', 'register', 'login'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // ── Rule Building ──────────────────────────────────────────────────────

    /**
     * Build the three cache rules.
     *
     * @param  array{exact: string[], prefixes: string[]}  $publicPaths
     * @return array[]
     */
    protected function buildDesiredRules(array $publicPaths): array
    {
        return [
            [
                'description' => self::RULE_PREFIX . ' Static assets — immutable (1yr)',
                'expression' => '(http.request.uri.path contains "/build/assets/" or '
                    . 'http.request.uri.path contains "/fonts/" or '
                    . 'http.request.uri.path contains "/icons/")',
                'action' => 'set_cache_settings',
                'edgeTtl' => 31536000,
                'browserTtl' => 31536000,
            ],
            [
                'description' => self::RULE_PREFIX . ' Public pages — anonymous (5min)',
                'expression' => $this->buildPublicExpression($publicPaths),
                'action' => 'set_cache_settings',
                'edgeTtl' => 300,
                'browserTtl' => 60,
            ],
        ];
    }

    protected function buildPublicExpression(array $publicPaths): string
    {
        $locales = config('app.available_locales', ['en', 'de']);

        // Free plan compatible: use starts_with instead of regex.
        // Match /en or /de (with optional trailing slash and sub-paths).
        $localeChecks = [];
        foreach ($locales as $locale) {
            $localeChecks[] = "http.request.uri.path eq \"/{$locale}\"";
            $localeChecks[] = "starts_with(http.request.uri.path, \"/{$locale}/\")";
        }

        $pathExpression = '(' . implode(' or ', $localeChecks) . ')';

        // Only for anonymous visitors
        return "{$pathExpression}"
            . " and not http.cookie contains \"roundup-games-session\""
            . " and not http.cookie contains \"XSRF-TOKEN\"";
    }

    // ── Formatting ─────────────────────────────────────────────────────────

    protected function formatRuleForApi(array $rule): array
    {
        return [
            'description' => $rule['description'],
            'expression' => $rule['expression'],
            'action' => $rule['action'],
            'enabled' => true,
            'action_parameters' => [
                'cache' => true,
                'edge_ttl' => ['mode' => 'override_origin', 'default' => $rule['edgeTtl']],
                'browser_ttl' => ['mode' => 'override_origin', 'default' => $rule['browserTtl']],
            ],
        ];
    }

    protected function isInSync(array $managedRules, array $desiredRules): bool
    {
        if (count($managedRules) !== count($desiredRules)) {
            return false;
        }

        $existing = array_values($managedRules);
        for ($i = 0; $i < count($desiredRules); $i++) {
            $e = $existing[$i] ?? [];
            $d = $desiredRules[$i];
            if (($e['description'] ?? '') !== $d['description']) {
                return false;
            }
            if (($e['expression'] ?? '') !== $d['expression']) {
                return false;
            }
        }

        return true;
    }

    // ── Dry Run ─────────────────────────────────────────────────────────────

    protected function showDryRun(array $desiredRules): void
    {
        $this->newLine();
        $this->info('=== Cache rules to apply ===');

        foreach ($desiredRules as $i => $rule) {
            $this->comment($rule['description']);
            $this->line('  Expression:');
            $wrapped = wordwrap($rule['expression'], 100, "\n    ");
            $this->line("    {$wrapped}");
            $this->line("  Edge TTL: {$rule['edgeTtl']}s / Browser TTL: {$rule['browserTtl']}s");
            $this->newLine();
        }

        $this->comment('How it works:');
        $this->line('  • Static assets → edge caches 1 year');
        $this->line('  • Public HTML (anonymous) → edge caches 5 min');
        $this->line('  • Authenticated pages → origin Cache-Control: private → CF bypasses');
        $this->line('  • Manual rules (not prefixed [roundup-auto]) are preserved');
    }
}

// ── Cloudflare API Client ──────────────────────────────────────────────────

class CloudflareClient
{
    private string $zoneId;
    private string $apiToken;
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct(string $zoneId, string $apiToken)
    {
        $this->zoneId = $zoneId;
        $this->apiToken = $apiToken;
    }

    /**
     * Get the entrypoint ruleset for cache settings.
     */
    public function getCacheRuleset(): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiToken}",
        ])->get("{$this->baseUrl}/zones/{$this->zoneId}/rulesets/phases/http_request_cache_settings/entrypoint");

        $this->checkResponse($response, 'get cache ruleset');

        return $response->json('result', []);
    }

    /**
     * Replace all rules in the cache ruleset (PUT).
     */
    public function putCacheRules(string $rulesetId, array $rules): void
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiToken}",
            'Content-Type' => 'application/json',
        ])->put("{$this->baseUrl}/zones/{$this->zoneId}/rulesets/{$rulesetId}", [
            'rules' => $rules,
        ]);

        $this->checkResponse($response, 'put cache rules');
    }

    /**
     * Purge everything from the zone cache.
     */
    public function purgeEverything(): void
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiToken}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/zones/{$this->zoneId}/purge_cache", [
            'purge_everything' => true,
        ]);

        $this->checkResponse($response, 'purge cache');
    }

    protected function checkResponse($response, string $context): void
    {
        if (! $response->successful()) {
            $errors = $response->json('errors', []);
            $msg = $errors[0]['message'] ?? $response->body();
            $code = $errors[0]['code'] ?? '?';

            $hint = '';
            if (str_contains($msg, 'authentication scheme') || $code === 10405) {
                $hint = "\n\n  The API token needs: Zone → Cache Rules → Edit permission.\n"
                    . "  Create at: https://dash.cloudflare.com/profile/api-tokens";
            }

            throw new \RuntimeException("Cloudflare API error ({$context}): {$msg}{$hint}");
        }

        if (! $response->json('success', false)) {
            $errors = $response->json('errors', []);
            $msg = $errors[0]['message'] ?? 'Unknown error';
            throw new \RuntimeException("Cloudflare API error ({$context}): {$msg}");
        }
    }
}
