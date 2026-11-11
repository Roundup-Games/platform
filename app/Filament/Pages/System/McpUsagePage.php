<?php

namespace App\Filament\Pages\System;

use App\Mcp\Servers\RoundupServer;
use App\Models\McpCall;
use App\Models\McpClient;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * MCP usage report (M079/S05): per-consumer telemetry for the agent
 * surface, aggregated from the S01 audit trail (mcp_calls) — which
 * consumer called which tool and how often, per-tool breakdown, latency,
 * and last activity. No new tables: the whole report rolls up one
 * grouped Eloquent aggregation over the append-only audit rows.
 *
 * Observability boundary (documented on the page): pre-dispatch
 * rejections — denials (401/403/400) and throttle events (429) — never
 * write audit rows by design (RateLimitAndAuditTest pins it); they
 * surface as mcp.reject / mcp.throttle structured log events. The page
 * says where they live instead of pretending to count them.
 */
class McpUsagePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    // 13 — after Search Performance (12), the System-group telemetry pages.
    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'mcp-usage';

    protected static ?string $title = 'MCP Usage';

    protected static ?string $navigationLabel = 'MCP Usage';

    protected string $view = 'filament.pages.system.mcp-usage';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getViewData(): array
    {
        $days = 7;

        [$consumers, $tools, $pairs] = $this->rollup($this->usageRows($days));

        return [
            'totals' => [
                'window_days' => $days,
                'window_calls' => array_sum(array_column($consumers, 'calls')),
                'active_consumers' => count($consumers),
                'distinct_tools' => count($tools),
                'registered_consumers' => McpClient::query()->count(),
                'all_time_calls' => McpCall::query()->count(),
            ],
            'consumers' => $consumers,
            'tools' => $tools,
            'pairs' => $pairs,
        ];
    }

    /**
     * The single grouped aggregation behind the report: one row per
     * (consumer, tool) pair over the window with the call count, exact
     * summed latency, and last-call timestamp, joined to the consumer
     * identity (client name, owner, revocation state) so the page answers
     * "which consumer called which tool and how often" without per-row
     * lookups. mcp_calls is qualified explicitly — the joined tables
     * also carry created_at/name columns.
     *
     * @return Collection<int, \stdClass>
     */
    private function usageRows(int $days): Collection
    {
        return McpCall::query()
            ->where('mcp_calls.created_at', '>=', now()->subDays($days))
            ->join('mcp_clients', 'mcp_clients.id', '=', 'mcp_calls.mcp_client_id')
            ->leftJoin('users', 'users.id', '=', 'mcp_clients.user_id')
            ->groupBy(
                'mcp_calls.mcp_client_id',
                'mcp_clients.name',
                'mcp_clients.revoked_at',
                'users.name',
                'mcp_calls.tool',
            )
            ->selectRaw(<<<'SQL'
                mcp_calls.mcp_client_id,
                mcp_clients.name as client_name,
                mcp_clients.revoked_at,
                users.name as owner_name,
                mcp_calls.tool,
                count(*) as calls,
                sum(mcp_calls.latency_ms) as total_latency_ms,
                max(mcp_calls.created_at) as last_call
                SQL)
            ->toBase()
            ->get();
    }

    /**
     * Roll the (consumer, tool) rows up into the three report tables:
     * per-consumer totals, per-tool totals, and the consumer × tool
     * matrix capped at the top 50 pairs by call count. Latency is
     * aggregated from the exact summed total, not an average of averages.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array{
     *     0: list<array{id: string, name: string, owner: string, revoked: bool, calls: int, tools: int, avg_latency_ms: int|null, last_call: Carbon|null}>,
     *     1: list<array{tool: string, scope: string, calls: int, consumers: int, avg_latency_ms: int|null}>,
     *     2: list<array{consumer: string, tool: string, calls: int}>,
     * }
     */
    private function rollup(Collection $rows): array
    {
        $consumers = [];
        $tools = [];
        $pairs = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $fields */
            $fields = (array) $row;

            $clientId = is_string($fields['mcp_client_id']) ? $fields['mcp_client_id'] : '(unknown)';
            $clientName = is_string($fields['client_name']) && $fields['client_name'] !== ''
                ? $fields['client_name']
                : '(unnamed client)';
            $owner = is_string($fields['owner_name']) && $fields['owner_name'] !== ''
                ? $fields['owner_name']
                // Defensive-only branch (M079 review): deleting a user
                // cascades mcp_clients → mcp_calls, so an ownerless row
                // cannot exist — this keeps a hand-seeded / future-schema
                // row honest instead of rendering a blank cell.
                : '(deleted user)';
            $tool = is_string($fields['tool']) && $fields['tool'] !== '' ? $fields['tool'] : '(unknown)';
            $calls = is_numeric($fields['calls']) ? (int) $fields['calls'] : 0;
            $totalLatency = is_numeric($fields['total_latency_ms']) ? (int) $fields['total_latency_ms'] : 0;
            $rawLastCall = $fields['last_call'];
            $lastCall = is_string($rawLastCall) && $rawLastCall !== '' ? Carbon::parse($rawLastCall) : null;

            $consumer = $consumers[$clientId] ?? [
                'id' => $clientId,
                'name' => $clientName,
                'owner' => $owner,
                'revoked' => $fields['revoked_at'] !== null,
                'calls' => 0,
                'tools' => 0,
                'total_latency_ms' => 0,
                'last_call' => null,
            ];
            $consumer['calls'] += $calls;
            $consumer['total_latency_ms'] += $totalLatency;
            $consumer['tools']++;

            if ($lastCall !== null && ($consumer['last_call'] === null || $lastCall->gt($consumer['last_call']))) {
                $consumer['last_call'] = $lastCall;
            }

            $consumers[$clientId] = $consumer;

            $toolRow = $tools[$tool] ?? [
                'tool' => $tool,
                'scope' => RoundupServer::requiredAbilityFor($tool)->value ?? '(unregistered)',
                'calls' => 0,
                'consumers' => 0,
                'total_latency_ms' => 0,
            ];
            $toolRow['calls'] += $calls;
            $toolRow['consumers']++;
            $toolRow['total_latency_ms'] += $totalLatency;
            $tools[$tool] = $toolRow;

            $pairs[] = ['consumer' => $clientName, 'tool' => $tool, 'calls' => $calls];
        }

        $consumers = array_map(
            fn (array $consumer): array => [
                ...$consumer,
                'avg_latency_ms' => $consumer['calls'] > 0
                    ? (int) round($consumer['total_latency_ms'] / $consumer['calls'])
                    : null,
            ],
            array_values($consumers),
        );

        $tools = array_map(
            fn (array $tool): array => [
                ...$tool,
                'avg_latency_ms' => $tool['calls'] > 0
                    ? (int) round($tool['total_latency_ms'] / $tool['calls'])
                    : null,
            ],
            array_values($tools),
        );

        usort($consumers, fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
        usort($tools, fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
        usort($pairs, fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return [
            $consumers,
            $tools,
            array_slice($pairs, 0, 50),
        ];
    }
}
