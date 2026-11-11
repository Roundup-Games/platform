<?php

namespace App\Livewire\Settings;

use App\Enums\McpAbility;
use App\Models\McpCall;
use App\Models\McpClient;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Agent Tokens section of the settings surface (M079/S01/T02, D277;
 * personal-agent experience R098): issuance, connection help, and the
 * per-agent servicing view.
 *
 * One consumer = one mcp_clients row + the Sanctum personal access token
 * it links (abilities live on the token; the client row carries identity
 * and lifecycle). The plaintext token is shown exactly once, at creation.
 * Revoking stamps the client's revoked_at and deletes the token in one
 * transaction — the agent's access dies immediately at the middleware
 * layer.
 *
 * Issuance is preset-first (R098): persona-shaped ability bundles
 * (companion read-only / full participant / GM workspace) with the raw
 * checkboxes kept behind an Advanced disclosure for custom sets. The
 * servicing list renders one card per agent with plain-language
 * permissions, honest usage stats aggregated from the mcp_calls audit
 * trail (today / this week / distinct tools), a recent-activity feed,
 * and the dormancy hint — the Google/Slack connected-apps pattern,
 * with real per-call activity instead of a bare last-used stamp.
 *
 * host/ops abilities are admin-issued (ops dual-gated at dispatch);
 * host self-service follows the Game Master role — the GM subscription
 * perk (R097/D278).
 *
 * @property Collection<int, McpClient> $clients
 * @property list<array{value: string, label: string, hint: string}> $availableAbilities
 * @property bool $canSelfIssueHost
 * @property list<array{value: string, label: string, description: string, abilities: list<string>, locked: bool}> $presets
 * @property array<int, array<string, mixed>> $agentCards
 */
class AgentTokens extends Component
{
    /** Self-issuance budget: at most five agent tokens per hour per user. */
    public const int ISSUANCE_LIMIT = 5;

    public const int ISSUANCE_DECAY_SECONDS = 3600;

    /** Ceiling on a user's ACTIVE (unrevoked) consumers: bounds credential
     *  accumulation (the hourly budget limits velocity, not totals) and
     *  keeps every live credential reachable — and revocable — from the
     *  list below. */
    public const int MAX_ACTIVE_CLIENTS = 10;

    /** An agent unused for this many days is flagged as dormant (R098). */
    public const int DORMANT_AFTER_DAYS = 30;

    /** Activity feed length per agent card. */
    public const int ACTIVITY_ROWS = 5;

    public const string PRESET_COMPANION = 'companion';

    public const string PRESET_PARTICIPANT = 'participant';

    public const string PRESET_GM = 'gm';

    /** Curated activity copy per registered tool (wire name => lang key). */
    private const ACTIVITY_LABELS = [
        'platform.info' => 'settings.activity_platform_info',
        'games.search' => 'settings.activity_games_search',
        'games.detail' => 'settings.activity_games_detail',
        'my.sessions' => 'settings.activity_my_sessions',
        'my.dashboard' => 'settings.activity_my_dashboard',
        'games.join' => 'settings.activity_games_join',
        'games.apply' => 'settings.activity_games_apply',
        'games.leave' => 'settings.activity_games_leave',
        'host.roster' => 'settings.activity_host_roster',
        'host.charter' => 'settings.activity_host_charter',
        'host.attendance-summary' => 'settings.activity_host_attendance_summary',
        'host.attendance.report' => 'settings.activity_host_attendance_report',
        'host.attendance.resolve' => 'settings.activity_host_attendance_resolve',
        'host.recap.write' => 'settings.activity_host_recap_write',
        'host.charter.amend' => 'settings.activity_host_charter_amend',
        'ops.tickets' => 'settings.activity_ops_tickets',
        'ops.member.lookup' => 'settings.activity_ops_member_lookup',
        'ops.moderation' => 'settings.activity_ops_moderation',
    ];

    public string $name = '';

    /** @var list<string> */
    public array $abilities = [];

    /** The selected ability preset (R098); raw abilities stay editable. */
    public string $preset = self::PRESET_COMPANION;

    /**
     * Plaintext of a freshly issued token — rendered once, then cleared by
     * the dismiss action (or the next page load). Server-set only, so the
     * client can never write to it.
     */
    #[Locked]
    public ?string $newToken = null;

    /**
     * Validate the form, check the active-credential ceiling, then consume
     * an issuance attempt and create the credential. Validation and the
     * ceiling run first so neither a mistyped form nor a capped-out list
     * ever burns the hourly issuance budget; the limiter still guards
     * every actual issuance (createToken is unreachable without passing
     * it).
     */
    public function createClient(): void
    {
        $user = authenticatedUser();

        // A prior issuance's plaintext must not ride the next round-trip
        // payload while validation errors render — the fresh banner is
        // replaced by the new issuance or dropped entirely.
        $this->newToken = null;

        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in(array_column($this->availableAbilities, 'value'))],
        ], [
            'abilities.*.in' => __('settings.error_agent_token_ability_not_allowed'),
        ]);

        $rateLimitKey = 'mcp-agent-tokens:'.$user->id;

        // Credential ceiling: refuse issuance while the user's active
        // consumer count is at the cap (revoke one to make room). Checked
        // BEFORE the limiter so a capped-out form never burns the hourly
        // issuance budget either (same rationale as validation-first).
        if ($user->mcpClients()->active()->count() >= self::MAX_ACTIVE_CLIENTS) {
            $this->addError('name', __('settings.error_agent_token_limit_reached', ['limit' => self::MAX_ACTIVE_CLIENTS]));

            return;
        }

        if (RateLimiter::attempt($rateLimitKey, self::ISSUANCE_LIMIT, fn (): bool => true, decaySeconds: self::ISSUANCE_DECAY_SECONDS) !== true) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($rateLimitKey) / 60));

            $this->addError('name', __('settings.error_agent_token_rate_limited', ['minutes' => $minutes]));

            return;
        }

        $newAccessToken = $user->createToken($this->name, $this->abilities);
        $abilities = $this->abilities;

        $client = $user->mcpClients()->create([
            'name' => $this->name,
            'personal_access_token_id' => $newAccessToken->accessToken->id,
        ]);

        $this->newToken = $newAccessToken->plainTextToken;
        $this->reset('name', 'abilities');
        unset($this->clients, $this->agentCards);

        Log::info('mcp.token_issued', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'abilities' => $abilities,
        ]);
    }

    /**
     * Clear the freshly-issued token banner: the plaintext leaves Livewire
     * component state (and with it the wire:snapshot and every subsequent
     * round-trip payload) instead of riding along until the next page load.
     */
    public function dismissNewToken(): void
    {
        $this->newToken = null;
    }

    /**
     * Adopt one of the ability presets (R098): sets the preset marker and
     * writes its ability bundle into the editable abilities state, so the
     * Advanced checkboxes reflect the choice. The GM preset is a no-op for
     * viewers who cannot self-issue host (the card renders locked for
     * them; a crafted call changes nothing — createClient re-validates
     * against the same allowed set either way).
     */
    public function selectPreset(string $preset): void
    {
        // Any interaction other than copy/Done drops a displayed plaintext
        // — it must not ride the snapshot through unrelated state changes.
        $this->newToken = null;

        foreach ($this->presets as $candidate) {
            if ($candidate['value'] === $preset && ! $candidate['locked']) {
                $this->preset = $preset;
                $this->abilities = $candidate['abilities'];

                return;
            }
        }
    }

    /**
     * Revoke one of the user's own agent credentials. Safe no-op for ids
     * that are not a uuid or belong to someone else.
     */
    public function revokeClient(string $clientId): void
    {
        // Same snapshot-hygiene rule as selectPreset(): a displayed
        // plaintext is dropped the moment the user acts elsewhere.
        $this->newToken = null;

        $clientId = uuid_or_null($clientId);

        if ($clientId === null) {
            return;
        }

        $user = authenticatedUser();

        $client = $user->mcpClients()->whereKey($clientId)->first();

        if ($client === null) {
            return;
        }

        $client->revoke();
        unset($this->clients, $this->agentCards);

        Log::info('mcp.token_revoked', [
            'user_id' => $user->id,
            'client_id' => $client->id,
        ]);
    }

    /**
     * The user's agent credentials, newest first, with the linked token
     * (abilities/last_used) eager-loaded.
     *
     * @return Collection<int, McpClient>
     */
    #[Computed]
    public function clients()
    {
        return authenticatedUser()
            ->mcpClients()
            ->with('personalAccessToken')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /**
     * Ability choices the current user may grant: read/write for everyone;
     * host additionally for active Game Masters (the GM membership perk,
     * R097/D278); ops for Platform Admins only.
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    #[Computed]
    public function availableAbilities(): array
    {
        $labels = [
            McpAbility::Read->value => [__('settings.label_agent_ability_read'), __('settings.hint_agent_ability_read')],
            McpAbility::Write->value => [__('settings.label_agent_ability_write'), __('settings.hint_agent_ability_write')],
            McpAbility::Host->value => [__('settings.label_agent_ability_host'), __('settings.hint_agent_ability_host')],
            McpAbility::Ops->value => [__('settings.label_agent_ability_ops'), __('settings.hint_agent_ability_ops')],
        ];

        $user = authenticatedUser();
        $cases = $user->hasRole('Platform Admin')
            ? McpAbility::cases()
            : McpAbility::selfServiceCases($user->isGM());

        return array_map(
            fn (McpAbility $ability): array => [
                'value' => $ability->value,
                'label' => $labels[$ability->value][0],
                'hint' => $labels[$ability->value][1],
            ],
            $cases,
        );
    }

    /**
     * Whether the viewer's ability list includes the host class — false
     * for everyone except active Game Masters and Platform Admins. Drives
     * the locked-state GM preset card (R097/R098): a viewer who cannot
     * self-issue host still learns where the perk lives.
     */
    #[Computed]
    public function canSelfIssueHost(): bool
    {
        $user = authenticatedUser();

        return $user->isGM() || $user->hasRole('Platform Admin');
    }

    /**
     * The issuance presets (R098): persona-shaped ability bundles with
     * plain-language descriptions. The GM preset renders locked (and is
     * a no-op in selectPreset) for viewers without GM status or admin.
     *
     * Icon discipline (MEM subset gotcha): every icon name lives here as
     * a literal 'icon' => entry in app/ code — the only form the subset
     * scanner reads — never in blade ternaries, which fall out of the
     * self-hosted font silently. Every name below is registered in
     * config/fonts.php.
     *
     * @return list<array{value: string, label: string, description: string, abilities: list<string>, locked: bool, icon: string}>
     */
    #[Computed]
    public function presets(): array
    {
        $hostAllowed = $this->canSelfIssueHost;

        return [
            [
                'value' => self::PRESET_COMPANION,
                'label' => __('settings.label_agent_preset_companion'),
                'description' => __('settings.description_agent_preset_companion'),
                'abilities' => [McpAbility::Read->value],
                'locked' => false,
                'icon' => 'visibility',
            ],
            [
                'value' => self::PRESET_PARTICIPANT,
                'label' => __('settings.label_agent_preset_participant'),
                'description' => __('settings.description_agent_preset_participant'),
                'abilities' => [McpAbility::Read->value, McpAbility::Write->value],
                'locked' => false,
                'icon' => 'how_to_reg',
            ],
            [
                'value' => self::PRESET_GM,
                'label' => __('settings.label_agent_preset_gm'),
                'description' => __('settings.description_agent_preset_gm'),
                'abilities' => [McpAbility::Read->value, McpAbility::Write->value, McpAbility::Host->value],
                'locked' => ! $hostAllowed,
                'icon' => 'casino',
            ],
        ];
    }

    /**
     * The connection-help app picker (R098): one entry per supported
     * app surface with its in-app setup path. Labels and paths are
     * resolved as STATIC __() calls so the key-resolution guard sees
     * them; icons follow the same 'icon' => discipline as presets().
     *
     * @return list<array{value: string, label: string, icon: string, path: string}>
     */
    #[Computed]
    public function agentApps(): array
    {
        return [
            [
                'value' => 'chatgpt',
                'label' => __('settings.label_agent_app_chatgpt'),
                'icon' => 'bolt',
                'path' => __('settings.content_agent_app_path_chatgpt'),
            ],
            [
                'value' => 'claude',
                'label' => __('settings.label_agent_app_claude'),
                'icon' => 'explore',
                'path' => __('settings.content_agent_app_path_claude'),
            ],
            [
                'value' => 'gemini',
                'label' => __('settings.label_agent_app_gemini'),
                'icon' => 'auto_awesome',
                'path' => __('settings.content_agent_app_path_gemini'),
            ],
            [
                'value' => 'other',
                'label' => __('settings.label_agent_app_other'),
                'icon' => 'more_horiz',
                'path' => __('settings.content_agent_app_path_other'),
            ],
        ];
    }

    /**
     * Scanner-visible icon registry for the ability chips (subset
     * discipline): one registered glyph per ability class.
     *
     * @var array<string, array{icon: string}>
     */
    private const ABILITY_ICONS = [
        McpAbility::Read->value => ['icon' => 'search'],
        McpAbility::Write->value => ['icon' => 'how_to_reg'],
        McpAbility::Host->value => ['icon' => 'casino'],
        McpAbility::Ops->value => ['icon' => 'shield'],
    ];

    /**
     * The registered glyph for an ability chip (see ABILITY_ICONS).
     */
    public function abilityIcon(string $value): string
    {
        return self::ABILITY_ICONS[$value]['icon'] ?? 'extension';
    }

    /**
     * The safety panel rows (R098): icon + plain-language guarantee, as
     * static data so both the icon subsetter and the translation-key
     * guard can see them.
     *
     * @return list<array{icon: string, text: string}>
     */
    #[Computed]
    public function safetyRows(): array
    {
        return [
            ['icon' => 'person', 'text' => __('settings.content_agent_safety_acts')],
            ['icon' => 'timer', 'text' => __('settings.content_agent_safety_limits')],
            ['icon' => 'history', 'text' => __('settings.content_agent_safety_audit')],
            ['icon' => 'block', 'text' => __('settings.content_agent_safety_stop')],
        ];
    }

    /**
     * The servicing view (R098): one enriched card per agent — status,
     * plain-language abilities, last used, honest usage stats aggregated
     * from the mcp_calls audit trail (today / this week / distinct tools
     * — the audit table holds dispatched calls only, so nothing
     * log-sourced is claimed), the recent-activity feed, and the
     * dormancy hint. Two query shapes over the whole client list: one
     * grouped aggregation for the stats, and one bounded per-client read
     * for the feeds (per-client, not one shared limit — a chatty agent
     * must not crowd its siblings out of their own activity feeds).
     *
     * Calendar windows follow the R089 community zone (Europe/Berlin):
     * "today" and "this week" name community wall-clock days/weeks, the
     * same convention every other today/this-week surface in the app
     * uses — not the UTC storage clock.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function agentCards(): array
    {
        $clients = $this->clients;

        if ($clients->isEmpty()) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, string> $clientIds */
        $clientIds = $clients->pluck('id');

        $todayStart = community_now()->startOfDay()->utc();
        $weekStart = community_now()->startOfWeek()->utc();

        /** @var \Illuminate\Support\Collection<string, \stdClass> $statRows */
        $statRows = McpCall::query()
            ->whereIn('mcp_client_id', $clientIds)
            ->where('created_at', '>=', $weekStart)
            ->groupBy('mcp_client_id')
            ->selectRaw(
                'mcp_client_id, count(*) filter (where created_at >= ?) as today_count, count(*) as week_count, count(distinct tool) as tool_count',
                [$todayStart],
            )
            ->toBase()
            ->get()
            ->keyBy(fn (\stdClass $row): string => (string) $row->mcp_client_id);

        // Per-client bounded read (the (mcp_client_id, created_at) index
        // serves each): one query per agent — bounded by the active-client
        // ceiling — instead of one shared limit(120) whose slots a single
        // chatty agent could fill, starving every sibling feed.
        $recentByClient = $clients->mapWithKeys(fn (McpClient $client): array => [
            $client->id => McpCall::query()
                ->whereBelongsTo($client, 'client')
                ->orderByDesc('created_at')
                ->limit(self::ACTIVITY_ROWS)
                ->get(),
        ]);

        $dormantCutoff = now()->subDays(self::DORMANT_AFTER_DAYS);

        return $clients->map(function (McpClient $client) use ($statRows, $recentByClient, $dormantCutoff): array {
            $stat = $statRows->get($client->id);
            $lastUsedAt = $client->personalAccessToken?->last_used_at;

            $activity = $recentByClient->get($client->id)
                ?->map(fn (McpCall $call): array => [
                    'label' => $this->activityLabel($call->tool),
                    'at' => $call->created_at,
                ])
                ->values()
                ->all() ?? [];

            return [
                'id' => $client->id,
                'name' => $client->name,
                'revoked' => $client->isRevoked(),
                'created_at' => $client->created_at,
                'last_used_at' => $lastUsedAt,
                'abilities' => $this->clientAbilities($client),
                'today' => is_numeric($stat?->today_count) ? (int) $stat->today_count : 0,
                'week' => is_numeric($stat?->week_count) ? (int) $stat->week_count : 0,
                'tools' => is_numeric($stat?->tool_count) ? (int) $stat->tool_count : 0,
                'activity' => $activity,
                'dormant' => ! $client->isRevoked()
                    && (($lastUsedAt !== null && $lastUsedAt->lt($dormantCutoff))
                        || ($lastUsedAt === null && $client->created_at !== null && $client->created_at->lt($dormantCutoff))),
            ];
        })->values()->all();
    }

    /**
     * Translate a raw token ability value into its display label.
     */
    public function abilityLabel(string $value): string
    {
        return match ($value) {
            McpAbility::Read->value => __('settings.label_agent_ability_read'),
            McpAbility::Write->value => __('settings.label_agent_ability_write'),
            McpAbility::Host->value => __('settings.label_agent_ability_host'),
            McpAbility::Ops->value => __('settings.label_agent_ability_ops'),
            default => $value,
        };
    }

    /**
     * The plain-language permission chip for an ability (R098): what the
     * agent can DO, not the scope string.
     */
    public function abilityChip(string $value): string
    {
        return match ($value) {
            McpAbility::Read->value => __('settings.label_agent_chip_read'),
            McpAbility::Write->value => __('settings.label_agent_chip_write'),
            McpAbility::Host->value => __('settings.label_agent_chip_host'),
            McpAbility::Ops->value => __('settings.label_agent_chip_ops'),
            default => $value,
        };
    }

    /**
     * One activity row's human sentence for an audited tool name (the
     * mcp_calls `tool` column). Registered tools map to curated copy;
     * unregistered names (agent-supplied, audit-only) fall back to the
     * raw wire name so the feed never lies about what ran.
     */
    public function activityLabel(string $tool): string
    {
        $key = self::ACTIVITY_LABELS[$tool] ?? null;

        return $key !== null ? __($key) : $tool;
    }

    /**
     * The linked token's last-used timestamp, rendered for the card row
     * (K002: user-facing dates go through format_date).
     */
    public function lastUsedAt(McpClient $client): string
    {
        $lastUsedAt = $client->personalAccessToken?->last_used_at;

        return $lastUsedAt !== null
            ? format_date($lastUsedAt, 'datetime')
            : __('settings.status_agent_token_never_used');
    }

    /**
     * The ability values granted to a client's token (revoked clients keep
     * history; their token row is deleted, so the list degrades to none).
     *
     * @return list<string>
     */
    public function clientAbilities(McpClient $client): array
    {
        $abilities = $client->personalAccessToken->abilities ?? [];

        return array_values(array_filter($abilities, is_string(...)));
    }

    public function render(): View
    {
        return view('livewire.settings.agent-tokens');
    }
}
