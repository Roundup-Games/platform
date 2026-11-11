<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The platform hello/info tool (M079/S01): the first tool every agent sees.
 *
 * Read-only, argument-free, and cheap by design — it exists so transport,
 * auth, and audit wiring can be exercised end-to-end without touching
 * domain data. Returns the application name, canonical URL, and locale so
 * a freshly connected agent can confirm which deployment it is talking to.
 */
#[Name(self::NAME)]
#[Description('Returns platform metadata about this Roundup Games deployment: application name, canonical URL, and locale. Takes no arguments.')]
class PlatformInfoTool extends Tool
{
    /**
     * The MCP tool name, single-sourced here. Tests, the disabled-tools
     * kill-switch (T03), and audit rows all reference this constant so the
     * wire name can never drift from the registered one.
     */
    public const NAME = 'platform.info';

    /**
     * The tool-class scope (D277) this tool requires — read-only discovery
     * metadata. Checked via tokenCan() by McpAuthentication before dispatch.
     */
    public const ABILITY = McpAbility::Read;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        return Response::json([
            'name' => config('app.name'),
            'url' => config('app.url'),
            'locale' => app()->getLocale(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            //
        ];
    }
}
