<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dispatched MCP tools/call message (M079/S01/T02, D277): the audit
 * row every later slice builds on. Written by McpAuthentication after the
 * message has been dispatched to the server.
 *
 * Append-only by design: created_at only, no updated_at, no mutation
 * surface. actor is the agent-provenance marker (M079/S03/T02) — stamped
 * 'agent' at write time. Arguments are stored ONLY as a sha256 hash
 * (arguments_hash) — the audit trail must never hold sensitive argument
 * values in the clear. target_type/target_id (M079/S04/T01, R090) link
 * the entity an ops call operated on (ticket id / user id) so the row
 * answers "which consumer, acting as which admin, touched which record".
 *
 * @property string $id
 * @property string $mcp_client_id
 * @property string|null $user_id
 * @property string $tool
 * @property string $actor
 * @property string $arguments_hash
 * @property string|null $target_type
 * @property string|null $target_id
 * @property int $latency_ms
 * @property Carbon $created_at
 */
class McpCall extends Model
{
    use HasPlatformUuid;

    public $timestamps = false;

    protected $fillable = [
        'mcp_client_id',
        'user_id',
        'tool',
        'actor',
        'arguments_hash',
        'target_type',
        'target_id',
        'latency_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<McpClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
