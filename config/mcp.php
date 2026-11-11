<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    'redirect_domains' => [
        // Deliberately EMPTY (M079 review): fail-closed baseline for the
        // D277 revisit trigger. This list governs RFC 7591 dynamic client
        // registration should Mcp::oauthRoutes() ever be enabled — the
        // shipped '*' default would accept any redirect domain. No OAuth
        // routes are registered today; keep this empty when they are.
        //
        // 'https://example.com',
        // 'http://localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Browser-based MCP clients send an Origin header; the package's
    | ValidateOrigin middleware rejects any origin that is not app.url or
    | listed here. Non-browser agent clients send no Origin header at all
    | and always pass — deliberate, since this surface is for agents.
    | Add origins here only when a browser-based client host is explicitly
    | granted access (M079/S01).
    |
    */

    'allowed_origins' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Consumer Rate Limits (T03)
    |--------------------------------------------------------------------------
    |
    | Every authenticated dispatch consumes one attempt from its consumer's
    | bucket: a generous "read" bucket for discovery/metadata calls, a
    | tighter "write" bucket for write-class tools (mcp:write/host/ops).
    | The bucket key is the mcp_clients uuid with a read/write suffix, so
    | consumers are throttled independently. rate_limit_tier selects the
    | row; unknown tiers fail safe to "standard".
    |
    */

    'rate_limits' => [
        'tiers' => [
            'standard' => [
                'read' => ['limit' => 60, 'decay_seconds' => 60],
                'write' => ['limit' => 10, 'decay_seconds' => 60],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Kill-Switch (T03)
    |--------------------------------------------------------------------------
    |
    | Tools named in this cache entry are refused at dispatch with a 403 —
    | revoking a tool must never require a release. The entry is written by
    | ops (McpAuthentication::disableTools) with the TTL below, so a
    | forgotten switch self-expires instead of bricking a tool forever.
    |
    */

    'disabled_tools' => [
        'cache_key' => 'mcp:disabled-tools',
        'ttl_seconds' => 86_400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Write-Tool Idempotency (T03)
    |--------------------------------------------------------------------------
    |
    | Write tools accept an idempotency_key argument: a retried call with
    | the same key replays the first stored answer instead of double-
    | executing (agents retry — design for it). Answers are cached per
    | principal + tool + hashed key for the TTL below; only successful
    | responses are stored, so a failed first attempt still retries.
    |
    */

    'idempotency' => [
        'cache_prefix' => 'mcp:idempotency',
        'ttl_seconds' => 86_400,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Clients
    |--------------------------------------------------------------------------
    |
    | Here you may configure named MCP clients that connect to external MCP
    | servers. Every client may define a URL for the remote server, or a
    | command and arguments for local servers plus connection options.
    |
    */

    'clients' => [
        'github' => [
            'url' => env('MCP_GITHUB_URL', 'https://api.githubcopilot.com/mcp/'),
            'token' => env('MCP_GITHUB_TOKEN'),
            'headers' => [
                'X-MCP-Readonly' => 'true',
            ],
        ],

        'linear' => [
            'url' => env('MCP_LINEAR_URL', 'https://mcp.linear.app/mcp'),
            'token' => env('MCP_LINEAR_TOKEN'),
        ],

        'playwright' => [
            'command' => 'npx',
            'args' => ['-y', '@playwright/mcp@latest', '--headless'],
        ],
    ],

];
