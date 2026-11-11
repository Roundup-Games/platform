<?php

namespace App\Mcp\Servers;

use App\Enums\McpAbility;
use App\Mcp\Tools\GamesApplyTool;
use App\Mcp\Tools\GamesDetailTool;
use App\Mcp\Tools\GamesJoinTool;
use App\Mcp\Tools\GamesLeaveTool;
use App\Mcp\Tools\GamesSearchTool;
use App\Mcp\Tools\HostAttendanceReportTool;
use App\Mcp\Tools\HostAttendanceResolveTool;
use App\Mcp\Tools\HostAttendanceSummaryTool;
use App\Mcp\Tools\HostCharterAmendTool;
use App\Mcp\Tools\HostCharterTool;
use App\Mcp\Tools\HostRecapWriteTool;
use App\Mcp\Tools\HostRosterTool;
use App\Mcp\Tools\MyDashboardTool;
use App\Mcp\Tools\MySessionsTool;
use App\Mcp\Tools\OpsMemberLookupTool;
use App\Mcp\Tools\OpsModerationTool;
use App\Mcp\Tools\OpsTicketsTool;
use App\Mcp\Tools\PlatformInfoTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The Roundup Games MCP server (M079): the single curated surface through
 * which authenticated agents interact with the platform.
 *
 * Tools are hand-registered here (never auto-discovered) and call services
 * directly — components and controllers never know MCP exists. This class,
 * routes/ai.php, and config/mcp.php are the only places that know the
 * laravel/mcp package exists (D276 seam design), so a transport swap never
 * touches tools, auth, or audit.
 */
#[Name('Roundup Games')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
    Agent surface for Roundup Games, a platform for finding and hosting in-person tabletop games and RPG campaigns.

    Start with platform.info to confirm which deployment you are talking to. Every tool is read-only unless its description says otherwise; writes require an mcp:write ability and accept an idempotency_key for safe retries.
    MARKDOWN)]
class RoundupServer extends Server
{
    /**
     * The tool surface is hand-curated and small; every registered tool
     * must be visible on the FIRST tools/list page (the package default
     * of 15 silently truncated the list once the ops tools (M079/S04)
     * pushed the registry past 15 — ClientAuthTest's registry pin caught
     * it: the advertised list and TOOL_REGISTRY may never drift).
     */
    public int $defaultPaginationLength = 50;

    /**
     * Wire name => tool class: the dispatch-time tool registry. Single
     * source of truth for scope checks in McpAuthentication (M079/S01/T02,
     * D277) — every registered tool declares its tool-class scope as a
     * public const ABILITY (McpAbility) on the tool class, and the
     * middleware resolves it here via requiredAbilityFor() before a
     * tools/call message is dispatched. ClientAuthTest pins this registry
     * against the live tools/list response so the two can never drift.
     *
     * @var array<string, class-string>
     */
    public const TOOL_REGISTRY = [
        PlatformInfoTool::NAME => PlatformInfoTool::class,
        GamesSearchTool::NAME => GamesSearchTool::class,
        GamesDetailTool::NAME => GamesDetailTool::class,
        MySessionsTool::NAME => MySessionsTool::class,
        MyDashboardTool::NAME => MyDashboardTool::class,
        GamesJoinTool::NAME => GamesJoinTool::class,
        GamesApplyTool::NAME => GamesApplyTool::class,
        GamesLeaveTool::NAME => GamesLeaveTool::class,
        HostRosterTool::NAME => HostRosterTool::class,
        HostCharterTool::NAME => HostCharterTool::class,
        HostAttendanceSummaryTool::NAME => HostAttendanceSummaryTool::class,
        HostAttendanceReportTool::NAME => HostAttendanceReportTool::class,
        HostAttendanceResolveTool::NAME => HostAttendanceResolveTool::class,
        HostRecapWriteTool::NAME => HostRecapWriteTool::class,
        HostCharterAmendTool::NAME => HostCharterAmendTool::class,
        OpsTicketsTool::NAME => OpsTicketsTool::class,
        OpsMemberLookupTool::NAME => OpsMemberLookupTool::class,
        OpsModerationTool::NAME => OpsModerationTool::class,
    ];

    protected array $tools = [
        PlatformInfoTool::class,
        GamesSearchTool::class,
        GamesDetailTool::class,
        MySessionsTool::class,
        MyDashboardTool::class,
        GamesJoinTool::class,
        GamesApplyTool::class,
        GamesLeaveTool::class,
        HostRosterTool::class,
        HostCharterTool::class,
        HostAttendanceSummaryTool::class,
        HostAttendanceReportTool::class,
        HostAttendanceResolveTool::class,
        HostRecapWriteTool::class,
        HostCharterAmendTool::class,
        OpsTicketsTool::class,
        OpsMemberLookupTool::class,
        OpsModerationTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];

    /**
     * The declared argument names a registered tool accepts (its schema()
     * keys), or null when the wire name is not in the registry. The
     * transport-level undeclared-argument discipline in McpAuthentication
     * (M079/S01/T03) resolves through here so the wire contract a call is
     * checked against is exactly the schema the server advertises in
     * tools/list — the two can never drift.
     *
     * @return list<string>|null
     */
    public static function declaredArgumentsFor(string $toolName): ?array
    {
        $toolClass = self::TOOL_REGISTRY[$toolName] ?? null;

        if ($toolClass === null) {
            return null;
        }

        return array_keys(app($toolClass)->schema(new JsonSchemaTypeFactory));
    }

    /**
     * Resolve the tool-class scope (D277) a tools/call dispatch must hold:
     * the ABILITY const the registered tool class declares. Null when the
     * wire name is not in the registry (the server answers tool-not-found)
     * or the tool declares no scope (ClientAuthTest pins that every
     * registry tool declares one).
     */
    public static function requiredAbilityFor(string $toolName): ?McpAbility
    {
        $toolClass = self::TOOL_REGISTRY[$toolName] ?? null;

        if ($toolClass === null || ! defined($toolClass.'::ABILITY')) {
            return null;
        }

        return constant($toolClass.'::ABILITY');
    }

    /**
     * Dispatch a raw JSON-RPC message, emitting the mcp.connect / mcp.call
     * structured transport events (D272 precedent: default channel, event
     * name + context shape like llms.cache_*).
     *
     * Overridden (not wrapped in middleware) so the events fire for every
     * dispatch path — HTTP and STDIO alike — and carry the tool name and
     * wall-clock duration of the whole message, not just the handler.
     * Rejections before dispatch (auth, throttling) are logged by the
     * middleware layer that produces them, not here.
     */
    public function handle(string $rawMessage): void
    {
        $message = json_decode($rawMessage, true);

        if (! is_array($message) || ! is_string($message['method'] ?? null)) {
            parent::handle($rawMessage);

            return;
        }

        $startedAt = microtime(true);

        try {
            parent::handle($rawMessage);
        } finally {
            $this->logTransportEvent($message, round((microtime(true) - $startedAt) * 1000, 2));
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function logTransportEvent(array $message, float $durationMs): void
    {
        $method = $message['method'] ?? null;

        if (! is_string($method)) {
            return;
        }

        if ($method === 'initialize' || $method === 'server/discover') {
            Log::debug('mcp.connect', array_filter([
                'protocol_version' => $this->protocolVersion($message),
                'duration_ms' => $durationMs,
            ], fn (mixed $value): bool => $value !== null));

            return;
        }

        if ($method === 'tools/call') {
            $tool = $this->param($message, 'name');

            Log::debug('mcp.call', [
                'tool' => is_string($tool) ? $tool : '(unknown)',
                'duration_ms' => $durationMs,
            ]);
        }
    }

    /**
     * Best-effort protocol version for the connect event, without assuming
     * any shape deeper than the top-level array: non-legacy messages carry
     * it in params._meta, legacy initialize requests in params.protocolVersion.
     *
     * @param  array<string, mixed>  $message
     */
    protected function protocolVersion(array $message): ?string
    {
        $version = $this->param($message, 'protocolVersion');

        if (is_string($version)) {
            return $version;
        }

        $meta = $this->param($message, '_meta');

        if (is_array($meta)) {
            $version = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;

            if (is_string($version)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Read a key from params defensively — the raw message shape is
     * agent-controlled, so nothing deeper than the top-level array is
     * trusted.
     *
     * @param  array<string, mixed>  $message
     */
    protected function param(array $message, string $key): mixed
    {
        $params = $message['params'] ?? null;

        if (! is_array($params)) {
            return null;
        }

        return $params[$key] ?? null;
    }
}
