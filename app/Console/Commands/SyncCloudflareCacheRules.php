<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class SyncCloudflareCacheRules extends Command
{
    protected $signature = 'cloudflare:cache-rules
                            {--dry-run : Show what would change without applying}
                            {--force : Apply even if no changes detected}
                            {--early-hints= : Also set the zone Early Hints setting (on|off; no change when omitted)}';

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

        if (! is_string($zoneId)) {
            $zoneId = null;
        }
        if (! is_string($apiToken)) {
            $apiToken = null;
        }
        if (! is_array($locales)) {
            $locales = ['en', 'de'];
        }

        $publicPaths = $this->resolvePublicPaths($locales);
        $this->info('Public path patterns: '.count($publicPaths));

        $desiredRules = $this->buildDesiredRules($publicPaths);

        if ($this->option('dry-run')) {
            $this->showDryRun($desiredRules);

            return self::SUCCESS;
        }

        if ($zoneId === null || $zoneId === '' || $apiToken === null || $apiToken === '') {
            $this->error('Missing Cloudflare credentials. Set CF_ZONE_ID and CF_API_TOKEN in .env');
            $this->line('');
            $this->line('The API token needs these permissions:');
            $this->line('  • Zone → Cache Rules → Edit');
            $this->line('  • Zone → Cache Purge → Purge');
            $this->line('  • Zone → Zone Settings → Edit (only for --early-hints)');

            return self::FAILURE;
        }

        $client = new CloudflareClient($zoneId, $apiToken);

        // Optional zone-setting toggle (CWV 2026-11): Early Hints replays the
        // Link headers emitted by EmitPreloadLinkHeaders as a 103 before the
        // origin answers. Independent of the cache-rule sync below: a failure
        // here warns and fails the exit code, but the sync still runs.
        $earlyHintsFailed = false;
        $earlyHints = $this->option('early-hints');
        if (is_string($earlyHints) && $earlyHints !== '') {
            if (! in_array($earlyHints, ['on', 'off'], true)) {
                $this->error('--early-hints accepts on|off only.');

                return self::FAILURE;
            }
            $this->info('Setting zone Early Hints setting to '.$earlyHints.'...');
            try {
                $client->setEarlyHints($earlyHints);
            } catch (\RuntimeException $e) {
                report($e);
                $this->warn($e->getMessage());
                $this->warn('Continuing with the cache-rule sync.');
                $earlyHintsFailed = true;
            }
        }

        // Fetch current rules
        $this->info('Fetching current Cloudflare cache rules...');
        $ruleset = $client->getCacheRuleset();
        if ($ruleset === []) {
            $this->error('Failed to fetch cache ruleset from Cloudflare.');

            return self::FAILURE;
        }
        $existingRules = $ruleset['rules'] ?? [];
        assert(is_array($existingRules));
        $rulesetId = is_string($ruleset['id'] ?? null) ? $ruleset['id'] : '';
        $this->line("  Ruleset: {$rulesetId}");
        $this->line('  Existing rules: '.count($existingRules));

        // Separate managed vs manual rules
        $managedRules = array_filter($existingRules, function (mixed $r) {
            if (! is_array($r)) {
                return false;
            }
            $desc = $r['description'] ?? '';

            return is_string($desc) && str_starts_with($desc, self::RULE_PREFIX);
        });
        $manualRules = array_filter($existingRules, function (mixed $r) {
            if (! is_array($r)) {
                return true;
            }
            $desc = $r['description'] ?? '';

            return ! is_string($desc) || ! str_starts_with($desc, self::RULE_PREFIX);
        });
        $this->line('  Managed (auto): '.count($managedRules));
        $this->line('  Manual (preserved): '.count($manualRules));

        // Build API-formatted managed rules
        $managedApiRules = array_map(fn (array $r) => $this->formatRuleForApi($r), $desiredRules);

        // Check if update is needed
        if (! $this->option('force') && $this->isInSync($managedRules, $desiredRules)) {
            $this->info('No changes needed — cache rules are in sync.');

            return $earlyHintsFailed ? self::FAILURE : self::SUCCESS;
        }

        // Merge: manual rules first (preserved), then managed rules
        $allRules = array_merge(
            array_values($manualRules),
            $managedApiRules,
        );

        $this->info('Applying '.count($managedApiRules).' managed cache rules...');
        foreach ($desiredRules as $rule) {
            $desc = is_string($rule['description'] ?? null) ? $rule['description'] : '';
            $this->line('  • '.$desc);
        }

        $client->putCacheRules($rulesetId, $allRules);

        // Purge cache so new rules take effect immediately
        $this->info('Purging Cloudflare cache...');
        $client->purgeEverything();

        $this->info('Cache rules synced successfully.');

        return $earlyHintsFailed ? self::FAILURE : self::SUCCESS;
    }

    // ── Route Resolution ───────────────────────────────────────────────────

    /**
     * Resolve public paths by scanning Laravel routes.
     *
     * Returns grouped path info used to build the Cloudflare filter expression.
     *
     * @param  array<mixed>  $locales
     * @return array{exact: string[], prefixes: string[]}
     */
    protected function resolvePublicPaths(array $locales): array
    {
        $locales = array_filter($locales, 'is_string'); /** @var array<int, string> $locales */
        $exactPaths = [];
        $prefixPaths = [];

        foreach ($locales as $locale) {
            $exactPaths["/{$locale}"] = true;
            $exactPaths["/{$locale}/"] = true;
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
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
            $pathAfterLocale = (string) preg_replace('#^\{locale\}/?#', '', $uri);

            // Check if this route has parameters
            $hasParams = preg_match('#\{[^}]+\}#', $pathAfterLocale) === 1;

            // Get the full path segments
            $segments = explode('/', $pathAfterLocale);
            $firstSegment = $segments[0];

            // Root-level parameter like {locale}/{id} — just a locale path
            if ($firstSegment !== '' && preg_match('/^\{.*\}$/', $firstSegment) === 1) {
                continue;
            }

            foreach ($locales as $locale) {
                if ($hasParams && $firstSegment !== '') {
                    // Dynamic route: use prefix match
                    $prefixPaths["/{$locale}/{$firstSegment}"] = true;
                } elseif ($firstSegment !== '') {
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

    /**
     * @param  array<int, string>  $middleware
     */
    protected function isAuthGated(array $middleware): bool
    {
        return array_intersect($middleware, [
            'auth', 'auth:api', 'verified', 'profile.complete', 'auth.session',
        ]) !== [];
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
     * @return array<int, array<string, mixed>>
     */
    protected function buildDesiredRules(array $publicPaths): array
    {
        return [
            [
                'description' => self::RULE_PREFIX.' Static assets — immutable (1yr)',
                'expression' => '(http.request.uri.path contains "/build/assets/" or '
                    .'http.request.uri.path contains "/fonts/" or '
                    .'http.request.uri.path contains "/icons/")',
                'action' => 'set_cache_settings',
                'edgeTtl' => 31536000,
                'browserTtl' => 31536000,
            ],
            [
                'description' => self::RULE_PREFIX.' Public pages — anonymous (5min)',
                'expression' => $this->buildPublicExpression($publicPaths),
                'action' => 'set_cache_settings',
                'edgeTtl' => 300,
                'browserTtl' => 60,
            ],
        ];
    }

    /**
     * @param  array{exact: string[], prefixes: string[]}  $publicPaths
     */
    protected function buildPublicExpression(array $publicPaths): string
    {
        $locales = config('app.available_locales', ['en', 'de']);
        if (! is_array($locales)) {
            $locales = ['en', 'de'];
        }

        // Free plan compatible: use starts_with instead of regex.
        // Match /en or /de (with optional trailing slash and sub-paths).
        $localeChecks = [];
        foreach ($locales as $locale) {
            $locale = is_string($locale) ? $locale : 'en';
            $localeChecks[] = "http.request.uri.path eq \"/{$locale}\"";
            $localeChecks[] = "starts_with(http.request.uri.path, \"/{$locale}/\")";
        }

        $pathExpression = '('.implode(' or ', $localeChecks).')';

        // Never cache paths whose pages embed per-session state (CSRF
        // tokens) or belong to operator surfaces — a cached login page
        // serves every visitor one dead token and strips Set-Cookie at
        // the edge (2026-11-04 incident). "ends_with" covers dynamic
        // segments (e.g. /en/events/{slug}/register) within the Free
        // plan's expression vocabulary.
        $exclusions = [
            'ends_with(http.request.uri.path, "/login")',
            'ends_with(http.request.uri.path, "/register")',
            'ends_with(http.request.uri.path, "/logout")',
            'http.request.uri.path contains "/forgot-password"',
            'http.request.uri.path contains "/reset-password"',
            'http.request.uri.path contains "/verify-email"',
        ];
        $exclusionExpression = 'not ('.implode(' or ', $exclusions).')';

        // Only for anonymous visitors
        return "({$pathExpression} and {$exclusionExpression})"
            .' and not http.cookie contains "roundup-games-session"'
            .' and not http.cookie contains "XSRF-TOKEN"';
    }

    // ── Formatting ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<mixed>  $managedRules
     * @param  array<int, array<string, mixed>>  $desiredRules
     */
    protected function isInSync(array $managedRules, array $desiredRules): bool
    {
        if (count($managedRules) !== count($desiredRules)) {
            return false;
        }

        $existing = array_values($managedRules);
        for ($i = 0; $i < count($desiredRules); $i++) {
            $e = $existing[$i] ?? [];
            if (! is_array($e)) {
                $e = [];
            }
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

    /**
     * @param  array<int, array<string, mixed>>  $desiredRules
     */
    protected function showDryRun(array $desiredRules): void
    {
        $this->newLine();
        $this->info('=== Cache rules to apply ===');

        foreach ($desiredRules as $i => $rule) {
            $desc = is_string($rule['description'] ?? null) ? $rule['description'] : 'Rule '.$i;
            $expr = is_string($rule['expression'] ?? null) ? $rule['expression'] : '';
            $edgeTtl = is_int($rule['edgeTtl'] ?? null) ? (string) $rule['edgeTtl'] : '?';
            $browserTtl = is_int($rule['browserTtl'] ?? null) ? (string) $rule['browserTtl'] : '?';
            $this->comment($desc);
            $this->line('  Expression:');
            $wrapped = wordwrap($expr, 100, "\n    ");
            $this->line("    {$wrapped}");
            $this->line("  Edge TTL: {$edgeTtl}s / Browser TTL: {$browserTtl}s");
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
     *
     * @return array<string, mixed>
     */
    public function getCacheRuleset(): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiToken}",
        ])->timeout(15)->connectTimeout(5)->get("{$this->baseUrl}/zones/{$this->zoneId}/rulesets/phases/http_request_cache_settings/entrypoint");

        $this->checkResponse($response, 'get cache ruleset');

        $result = $response->json('result', []);

        return is_array($result) ? $result : [];
    }

    /**
     * Replace all rules in the cache ruleset (PUT).
     *
     * @param  array<mixed>  $rules
     */
    public function putCacheRules(string $rulesetId, array $rules): void
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiToken}",
            'Content-Type' => 'application/json',
        ])->timeout(15)->connectTimeout(5)->put("{$this->baseUrl}/zones/{$this->zoneId}/rulesets/{$rulesetId}", [
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
        ])->timeout(15)->connectTimeout(5)->post("{$this->baseUrl}/zones/{$this->zoneId}/purge_cache", [
            'purge_everything' => true,
        ]);

        $this->checkResponse($response, 'purge cache');
    }

    /**
     * Set the zone-level Early Hints setting ("on"|"off").
     *
     * Cloudflare caches Link headers (rel=preload/preconnect) from prior
     * 200 responses and replays them as 103 Early Hints on subsequent
     * requests while the origin is still generating the response.
     */
    public function setEarlyHints(string $value): void
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiToken}",
            'Content-Type' => 'application/json',
        ])->timeout(15)->connectTimeout(5)->patch("{$this->baseUrl}/zones/{$this->zoneId}/settings/early_hints", [
            'value' => $value,
        ]);

        $this->checkResponse($response, 'set early hints');
    }

    protected function checkResponse(Response $response, string $context): void
    {
        if (! $response->successful()) {
            $errors = $response->json('errors', []);
            $errors = is_array($errors) ? $errors : [];
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $msg = is_string($first['message'] ?? null) ? $first['message'] : $response->body();
            $code = $first['code'] ?? '?';

            $hint = '';
            if (str_contains($msg, 'authentication scheme') || $code === 10405) {
                $hint = "\n\n  The API token needs: Zone → Cache Rules → Edit permission.\n"
                    .'  Create at: https://dash.cloudflare.com/profile/api-tokens';
            } elseif ($context === 'set early hints' && ($code === 9109 || str_contains($msg, 'Unauthorized to access requested resource'))) {
                $hint = "\n\n  The API token needs: Zone → Zone Settings → Edit permission.\n"
                    .'  Edit at: https://dash.cloudflare.com/profile/api-tokens';
            }

            throw new \RuntimeException("Cloudflare API error ({$context}): {$msg}{$hint}");
        }

        if ($response->json('success', false) !== true) {
            $errors = $response->json('errors', []);
            $errors = is_array($errors) ? $errors : [];
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $msg = is_string($first['message'] ?? null) ? $first['message'] : 'Unknown error';
            throw new \RuntimeException("Cloudflare API error ({$context}): {$msg}");
        }
    }
}
