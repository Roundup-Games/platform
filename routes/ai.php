<?php

use App\Http\Middleware\McpAuthentication;
use App\Mcp\Servers\RoundupServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes (M079)
|--------------------------------------------------------------------------
|
| Loaded by laravel/mcp's service provider via Route::group([], ...) — no
| middleware group, so agents POST here without CSRF tokens (no 419s; the
| surface authenticates via McpAuthentication, mirroring the webhook
| precedents where signature/auth replaces CSRF). The package's own
| middleware validates the Origin header (non-browser agents send none and
| pass), MCP protocol headers, and adds the WWW-Authenticate challenge.
|
*/

Mcp::web('/mcp', RoundupServer::class)->middleware(McpAuthentication::class);
