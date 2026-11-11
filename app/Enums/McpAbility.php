<?php

namespace App\Enums;

/**
 * MCP tool-class scopes (M079/S01/T02, D277).
 *
 * These are the Sanctum personal-access-token abilities an agent token can
 * carry — NOT spatie roles and NOT per-route permissions. One ability per
 * tool class; each tool declares its requirement as a const on the tool
 * class (see PlatformInfoTool::ABILITY) and McpAuthentication checks it
 * with tokenCan() before dispatch:
 *
 *  - read  — discovery/metadata tools (the default for every issued token)
 *  - write — tools that create or mutate the user's own data; write tools
 *            additionally accept an idempotency_key (T03)
 *  - host  — host-workspace tooling beyond the user's own data; a Game
 *            Master membership perk (R097/D278): self-service for holders
 *            of the active Game Master role, dual-gated at dispatch
 *            (token ability AND Game Master or Platform Admin role)
 *  - ops   — platform operations surface; admin-issued only and dual-gated:
 *            the token needs the ability AND its owner needs the Platform
 *            Admin role at dispatch time
 */
enum McpAbility: string
{
    case Read = 'mcp:read';

    case Write = 'mcp:write';

    case Host = 'mcp:host';

    case Ops = 'mcp:ops';

    /**
     * Abilities a user may grant themselves from settings (M079/S01/T02;
     * host added by R097/D278). read/write for everyone; host additionally
     * for holders of the active Game Master role — the runtime marker of
     * GM status whose lifecycle (GmRoleService, MembershipEnded) is coupled
     * to GM subscription lapse, so tokens die with the role. ops stays
     * admin-issued (and is additionally dual-gated at dispatch).
     *
     * The GM marker is passed in as a bool by the caller (the settings
     * surface reads isGM()): the enum namespace is dependency-light by
     * arch rule (ArchitectureTest) and must not import App\Models.
     *
     * @return list<self>
     */
    public static function selfServiceCases(bool $isGameMaster = false): array
    {
        return $isGameMaster
            ? [self::Read, self::Write, self::Host]
            : [self::Read, self::Write];
    }
}
