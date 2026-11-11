<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Mcp\Concerns\LinksAuditTarget;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The ops member lookup tool (M079/S04/T01, R090): a support agent's
 * "who is this member and can I trust them" check.
 *
 * The payload mirrors the admin user view's support-relevant subset —
 * identity, account/ban state, roles, reliability tier, membership
 * status — and NOTHING more. Phone, gender, pronouns, bio, timezone, and
 * location are deliberately absent: they are not support-relevant, and
 * the tool surface must not become a raw PII dump.
 *
 * Dual-gated (D277): the middleware requires BOTH the mcp:ops token
 * ability AND the Platform Admin role; the tool re-checks the role as
 * defense in depth.
 *
 * Audit: the looked-up member's id is linked into the mcp_calls row
 * (target_type 'user') — a lookup of member data leaves exactly the
 * trail an SSH/artisan grep would have left, but attributed.
 */
#[Name(self::NAME)]
#[Description('Support lookup of one member by id or email: identity, account status (ban state), roles, reliability tier, and membership status — the admin user view\'s support-relevant subset, nothing more. Every lookup is audit-linked to the target member.')]
class OpsMemberLookupTool extends Tool
{
    use LinksAuditTarget;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'ops.member.lookup';

    /** Tool-class scope (D277) — dual-gated with the Platform Admin role. */
    public const ABILITY = McpAbility::Ops;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        // Defense in depth: the gate already dual-checked (mcp:ops ability +
        // Platform Admin role); a fail-closed tool never assumes the gate ran.
        if (! $user->hasRole('Platform Admin')) {
            return $this->denial('not_authorized', 'The ops surface requires an active Platform Admin.');
        }

        $args = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $userId = is_string($args['user_id'] ?? null) ? $args['user_id'] : null;
        $email = is_string($args['email'] ?? null) && $args['email'] !== '' ? $args['email'] : null;

        if (($userId === null) === ($email === null)) {
            return $this->denial('invalid_arguments', 'Provide exactly one of user_id or email.');
        }

        /** @var User|null $member */
        $member = $userId !== null
            ? User::query()->find($userId)
            : User::query()->whereRaw('lower(email) = lower(?)', [$email])->first();

        if ($member === null) {
            return $this->denial('not_found', 'No member matches the given id or email.');
        }

        // The lookup itself is the audited act: link the target member into
        // this call's audit row before answering.
        $this->linkAuditTarget('user', $member->id);

        return Response::json([
            'status' => 'found',
            'user' => $this->memberPayload($member),
        ]);
    }

    /**
     * The support-relevant member payload — a strict subset of the admin
     * user view. Identity + ban state + roles + reliability + membership
     * only; every profile/PII field beyond that stays off the agent
     * surface by design.
     *
     * @return array<string, mixed>
     */
    private function memberPayload(User $member): array
    {
        $reliability = $member->reliability_score;

        return [
            'id' => $member->id,
            'name' => $member->name,
            'slug' => $member->slug,
            'email' => $member->email,
            'profile_url' => route('profile.public', $member),
            'created_at' => $member->created_at?->toIso8601String(),
            'account' => [
                'is_disabled' => $member->is_disabled,
                'disabled_at' => $member->disabled_at?->toIso8601String(),
                'email_verified' => $member->email_verified_at !== null,
                'profile_complete' => $member->profile_complete,
                'has_password' => $member->hasPasswordSet(),
            ],
            'roles' => $member->getRoleNames()->values()->all(),
            'reliability' => [
                'tier' => is_array($reliability) && is_string($reliability['tier'] ?? null)
                    ? $reliability['tier']
                    : 'newcomer',
                'score' => is_array($reliability) && is_numeric($reliability['score'] ?? null)
                    ? (float) $reliability['score']
                    : 0.0,
                'games' => is_array($reliability) && is_numeric($reliability['game_count'] ?? null)
                    ? (int) $reliability['game_count']
                    : 0,
                'computed_at' => $member->reliability_computed_at !== null
                    ? Carbon::parse($member->reliability_computed_at)->toIso8601String()
                    : null,
            ],
            'membership' => [
                'supporting_member' => $member->hasActiveMembership(),
                'gm_subscription' => $member->hasGmSubscription(),
                'comp' => $member->activeComp !== null
                    ? ['ends_at' => $member->activeComp->ends_at?->toIso8601String()]
                    : null,
            ],
        ];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()->description('The member\'s UUID. Provide exactly one of user_id or email.'),
            'email' => $schema->string()->description('The member\'s email address (case-insensitive). Provide exactly one of user_id or email.'),
        ];
    }

    /**
     * Structured denial: stable snake_case code + message, mirroring the
     * host tools' denial convention so agents can branch without parsing.
     */
    private function denial(string $code, string $message): Response
    {
        return Response::error($code.': '.$message);
    }
}
