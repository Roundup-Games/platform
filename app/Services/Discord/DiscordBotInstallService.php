<?php

namespace App\Services\Discord;

use App\Exceptions\DiscordBotInstallException;
use App\Models\DiscordGuild;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The landlord bot-install flow for a Discord guild (T06).
 *
 * Discord's bot-install is a standard OAuth2 authorization-code round-trip:
 *   1. The (roundup-authenticated) landlord clicks an "Add to Server" link
 *      built by {@see installUrl()} — the OAuth2 add-app URL carrying the
 *      `bot applications.commands` scope set. Discord asks them to pick the
 *      guild and approve the bot's permissions.
 *   2. Discord redirects back to {@see completeInstall()} with an
 *      authorization `code`. roundup exchanges it for a bot access token
 *      (the code is single-use), then calls the guild-detail endpoint to
 *      confirm the install landed in the guild the landlord chose.
 *   3. A {@see DiscordGuild} row is created-or-updated keyed by the Discord
 *      guild snowflake, with the roundup user recorded as `owner_user_id`.
 *   4. An onboarding message is posted to the guild's system channel (if
 *      the bot can see one) via the shared {@see DiscordWebhookClient},
 *      telling members roundup is live and the landlord should pick channels.
 *
 * Distinct from the login OAuth client in config/services.php: the bot is the
 * M057 event-bridge application (D118). Both default to the same client id/
 * secret for single-application setups, but production runs them as separate
 * applications.
 *
 * Auth model: the guild-detail and channel-list reads use the bot's
 * application token (`Authorization: Bot {token}`) — NOT the short-lived user
 * access token from the code exchange — because the bot is the identity that
 * will read/write channels long-term. This matches {@see DiscordWebhookClient}.
 *
 * Channel picker: {@see listChannels()} returns the text/forum channels the
 * landlord can route roundup to, reduced to {id,name,type} so the Livewire
 * settings surface (T06) can render a stable picker without Discord's full
 * channel object.
 */
class DiscordBotInstallService
{
    /** Discord OAuth2 add-app base URL. */
    private const OAUTH_AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';

    /** Discord OAuth2 token endpoint. */
    private const OAUTH_TOKEN_URL = 'https://discord.com/api/oauth2/token';

    private string $baseUrl;

    private string $botToken;

    private string $clientId;

    private string $clientSecret;

    private string $redirectUri;

    public function __construct(
        ?string $baseUrl = null,
        ?string $botToken = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $redirectUri = null,
    ) {
        $configured = is_string($u = config('services.discord.api_base_url')) ? $u : 'https://discord.com/api/v10';
        $this->baseUrl = rtrim($baseUrl ?? $configured, '/');
        $this->botToken = $botToken ?? (is_string($t = config('services.discord.bot_token')) ? $t : '');
        $this->clientId = $clientId ?? (is_string($c = config('services.discord.bot_client_id')) ? $c : '');
        $this->clientSecret = $clientSecret ?? (is_string($s = config('services.discord.bot_client_secret')) ? $s : '');
        $this->redirectUri = $redirectUri ?? (is_string($r = config('services.discord.bot_redirect_uri')) ? $r : '');
    }

    /**
     * Build the Discord OAuth2 "Add to Server" URL for a landlord.
     *
     * Requests `bot applications.commands identify guilds`: the bot/command
     * scopes install the application, while `identify guilds` continues into a
     * user authorization-code grant so Discord returns a user access_token.
     * That token authorizes the guild-ownership verification in
     * {@see completeInstall()} — without it the bot scopes alone prove only
     * that some authorization happened, not that the installer controls the
     * guild they claim in the callback URL.
     *
     * @param  int  $permissions  Discord permission integer the bot requests
     *                            on install (default: View Channels + Send
     *                            Messages + Embed Links + Read Message History).
     * @param  string  $state  Opaque anti-CSRF token round-tripped via the
     *                         callback; verified against the install session.
     * @param  string  $codeChallenge  PKCE S256 challenge derived from the
     *                                 verifier stored in the install session.
     */
    public function installUrl(int $permissions = 3264174460928, string $state = '', string $codeChallenge = ''): string
    {
        $query = [
            'client_id' => $this->clientId,
            'scope' => 'bot applications.commands identify guilds',
            'permissions' => $permissions,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
        ];

        if ($state !== '') {
            $query['state'] = $state;
        }

        if ($codeChallenge !== '') {
            $query['code_challenge'] = $codeChallenge;
            $query['code_challenge_method'] = 'S256';
        }

        return self::OAUTH_AUTHORIZE_URL.'?'.http_build_query($query);
    }

    /**
     * Generate a random opaque install-session state token (anti-CSRF).
     */
    public static function generateState(): string
    {
        return Str::random(40);
    }

    /**
     * Generate a high-entropy PKCE code_verifier (43–128 chars of the
     * unreserved set; alphanumeric is a safe subset).
     */
    public static function generatePkceVerifier(): string
    {
        return Str::random(64);
    }

    /**
     * Derive the S256 PKCE code_challenge from a verifier (base64url of the
     * SHA-256 hash, per RFC 7636).
     */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Complete the bot install from the OAuth2 authorization-code callback.
     *
     * Security model (closes the guild-takeover vector):
     *   1. Exchange the code WITH the PKCE verifier → Discord returns a user
     *      access_token (the `identify guilds` scopes make this a user grant).
     *   2. Verify the installer actually owns or has MANAGE_GUILD on the
     *      target guild, using that user token against /users/@me/guilds.
     *      The callback `guild_id` is attacker-controllable; the bot scopes
     *      alone prove only that some authorization happened.
     *   3. Only then record the installer as the roundup guild owner and fetch
     *      the guild detail (name/icon) with the bot token.
     *
     * @param  User  $landlord  The roundup user who clicked install; recorded
     *                          as discord_guilds.owner_user_id.
     * @param  string  $code  The OAuth2 authorization code (single-use).
     * @param  string  $guildId  The guild snowflake from the callback query
     *                           string. Validated as a snowflake and then
     *                           verified against the installer's membership.
     * @param  string  $codeVerifier  The PKCE verifier from the install session;
     *                                required to redeem the code.
     * @return DiscordGuild The created-or-updated guild mapping.
     *
     * @throws DiscordBotInstallException on a malformed guild_id, a failed code
     *                                    exchange, a failed ownership check,
     *                                    or a guild-fetch failure
     */
    public function completeInstall(User $landlord, string $code, string $guildId, string $codeVerifier = ''): DiscordGuild
    {
        if (! $this->isValidSnowflake($guildId)) {
            throw DiscordBotInstallException::missingGuildId();
        }

        // Exchange the code (with the PKCE verifier) for tokens. The user
        // access_token returned here authorizes the ownership check below —
        // the bot scopes alone yield no user token, so `identify guilds` is
        // what makes verification possible.
        $tokenBody = $this->exchangeCode($code, $codeVerifier);
        $userAccessToken = is_string($tokenBody['access_token'] ?? null)
            ? $tokenBody['access_token']
            : '';

        // Bind the claimed guild to the installer's actual authorization:
        // confirm they own or administer the guild before recording them as
        // its roundup owner. This is the control the bot scopes do NOT give.
        if (! $this->installerManagesGuild($userAccessToken, $guildId)) {
            throw DiscordBotInstallException::guildNotOwned($guildId);
        }

        $guildSnowflake = $guildId;

        $detail = $this->fetchGuildDetail($guildSnowflake);

        $guild = DiscordGuild::updateOrCreate(
            ['guild_id' => $guildSnowflake],
            [
                'name' => is_string($detail['name'] ?? null) ? $detail['name'] : "Guild {$guildSnowflake}",
                'icon' => is_string($detail['icon'] ?? null) ? $detail['icon'] : null,
                'owner_user_id' => $landlord->id,
                // Channels are null until the landlord picks them (T06 settings surface).
                'locale' => is_string($detail['preferred_locale'] ?? null) ? $detail['preferred_locale'] : null,
            ],
        );

        Log::info('discord_bot_install.guild_installed', [
            'guild_id' => $guildSnowflake,
            'row_id' => $guild->id,
            'owner_user_id' => $landlord->id,
            'row_action' => $guild->wasRecentlyCreated ? 'created' : 'updated',
            'status' => 'installed',
        ]);

        $this->postOnboardingMessage($guild, $detail);

        return $guild;
    }

    /**
     * List the channels a landlord can route roundup to in a guild.
     *
     * Returns text and forum channels (the postable surfaces) reduced to
     * {id,name,type} so the Livewire picker renders a stable list. Guild
     * categories, voice channels, and stage channels are filtered out —
     * roundup cards belong in text/forum channels.
     *
     * @return list<array{id: string, name: string, type: int}>
     *
     * @throws DiscordBotInstallException on channel-list fetch failure
     */
    public function listChannels(DiscordGuild $guild): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => 'Bot '.$this->botToken])
                ->get("{$this->baseUrl}/guilds/{$guild->guild_id}/channels");
        } catch (ConnectionException $e) {
            throw DiscordBotInstallException::channelFetchFailed($guild->guild_id, 0);
        }

        if ($response->failed()) {
            Log::error('discord_bot_install.channel_fetch_failed', [
                'guild_id' => $guild->guild_id,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw DiscordBotInstallException::channelFetchFailed($guild->guild_id, $response->status());
        }

        $channels = $response->json();
        if (! is_array($channels)) {
            return [];
        }

        // Discord channel types: 0 = text, 5 = announcement, 15 = forum.
        // Voice (2), stage (13), category (4), directory (14) excluded.
        $postable = [0, 5, 15];

        $list = [];
        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }

            $id = $channel['id'] ?? null;
            $name = $channel['name'] ?? null;
            $type = $channel['type'] ?? null;

            if (! is_string($id) || ! is_string($name) || ! is_int($type) || ! in_array($type, $postable, true)) {
                continue;
            }

            $list[] = ['id' => $id, 'name' => $name, 'type' => $type];
        }

        // Stable: alphabetical by name so the picker order is deterministic.
        usort($list, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $list;
    }

    // ── OAuth2 code exchange ───────────────────────────

    /**
     * Exchange the authorization code for tokens.
     *
     * Sends the PKCE `code_verifier` so Discord binds the code to the install
     * session that issued the challenge — defense against an intercepted
     * authorization code. Returns the full token body; the `access_token` it
     * carries (a user token, because the install scopes include `identify
     * guilds`) authorizes the ownership check in {@see completeInstall()}.
     *
     * @return array<string, mixed> The token response body.
     *
     * @throws DiscordBotInstallException on exchange failure
     */
    private function exchangeCode(string $code, string $codeVerifier = ''): array
    {
        if ($code === '') {
            throw DiscordBotInstallException::tokenExchangeFailed(0, 'empty authorization code');
        }

        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ];

        if ($codeVerifier !== '') {
            $params['code_verifier'] = $codeVerifier;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::OAUTH_TOKEN_URL, $params);
        } catch (ConnectionException $e) {
            throw DiscordBotInstallException::tokenExchangeFailed(0, 'connection: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw DiscordBotInstallException::tokenExchangeFailed($response->status(), $response->body());
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Verify the installer owns or holds MANAGE_GUILD on the claimed guild.
     *
     * Uses the user access_token from the code exchange to read the installer's
     * guild membership. The `/users/@me/guilds` payload carries per-guild
     * `owner` and `permissions` flags; a match on the target snowflake proves
     * the installer is an administrator there. This is the control that closes
     * the guild-takeover vector — the callback `guild_id` is otherwise
     * attacker-controllable.
     *
     * Best-effort: any failure to resolve membership (no token, network error,
     * non-2xx, guild absent from the list) fails closed — the install is
     * refused rather than recording an unverified owner.
     */
    private function installerManagesGuild(string $accessToken, string $guildSnowflake): bool
    {
        if ($accessToken === '') {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get("{$this->baseUrl}/users/@me/guilds");
        } catch (ConnectionException) {
            return false;
        }

        if ($response->failed()) {
            Log::warning('discord_bot_install.membership_check_failed', [
                'guild_id' => $guildSnowflake,
                'status' => $response->status(),
            ]);

            return false;
        }

        $guilds = $response->json();
        if (! is_array($guilds)) {
            return false;
        }

        foreach ($guilds as $guild) {
            if (! is_array($guild) || ($guild['id'] ?? null) !== $guildSnowflake) {
                continue;
            }

            $isOwner = ($guild['owner'] ?? false) === true;
            $permissions = is_string($guild['permissions'] ?? null) && ctype_digit($guild['permissions'])
                ? (int) $guild['permissions']
                : 0;

            // MANAGE_GUILD = 0x20 (32).
            return $isOwner || ($permissions & 32) !== 0;
        }

        return false;
    }

    /**
     * Whether a string is a Discord snowflake-shaped guild id (17–20 digits).
     */
    private function isValidSnowflake(string $value): bool
    {
        return $value !== '' && preg_match('/^[0-9]{17,20}$/', $value) === 1;
    }

    /**
     * Fetch guild detail (name, icon, preferred_locale, system_channel) via
     * the bot's application token. Confirms the bot is actually installed in
     * the guild and gives us the canonical name/icon to store.
     *
     * @return array<string, mixed>
     *
     * @throws DiscordBotInstallException on fetch failure
     */
    private function fetchGuildDetail(string $guildSnowflake): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => 'Bot '.$this->botToken])
                ->get("{$this->baseUrl}/guilds/{$guildSnowflake}");
        } catch (ConnectionException $e) {
            throw DiscordBotInstallException::guildFetchFailed($guildSnowflake, 0);
        }

        if ($response->failed()) {
            throw DiscordBotInstallException::guildFetchFailed($guildSnowflake, $response->status());
        }

        $detail = $response->json();

        return is_array($detail) ? $detail : [];
    }

    // ── Onboarding message ─────────────────────────────

    /**
     * Post the onboarding message to the guild's system channel (if the bot
     * can see one). Best-effort: a failure is logged but never throws — the
     * install has already succeeded (the guild row exists), and the landlord
     * reaches the settings surface next regardless.
     *
     * @param  array<string, mixed>  $guildDetail
     */
    private function postOnboardingMessage(DiscordGuild $guild, array $guildDetail): void
    {
        $message = config('services.discord.install_onboarding_message');
        if (! is_string($message) || $message === '') {
            // Default onboarding text when none configured.
            $message = '🎉 roundup is installed! The server owner can now pick a games channel in the roundup settings to start publishing event cards.';
        }

        $systemChannel = is_string($guildDetail['system_channel_id'] ?? null)
            ? $guildDetail['system_channel_id']
            : null;

        if (! $systemChannel) {
            Log::info('discord_bot_install.no_system_channel', [
                'guild_id' => $guild->guild_id,
                'status' => 'onboarding_skipped',
                'reason' => 'no_system_channel',
            ]);

            return;
        }

        try {
            $client = app(DiscordWebhookClient::class);
            $client->postMessage(
                $systemChannel,
                DiscordWebhookPayload::embed(
                    embed: [
                        'title' => 'roundup is live in this server',
                        'description' => $message,
                        'color' => 0x5865F2,
                    ],
                    components: [],
                ),
            );

            Log::info('discord_bot_install.onboarding_posted', [
                'guild_id' => $guild->guild_id,
                'channel_id' => $systemChannel,
                'status' => 'onboarding_posted',
            ]);
        } catch (\Throwable $e) {
            Log::warning('discord_bot_install.onboarding_failed', [
                'guild_id' => $guild->guild_id,
                'channel_id' => $systemChannel,
                'status' => 'onboarding_failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the locale-prefixed path to the guild-settings Livewire page.
     * The install callback redirects here after creating the guild row.
     */
    public static function settingsPath(DiscordGuild $guild): string
    {
        $locale = session('locale', config('app.fallback_locale'));
        $locale = is_string($locale) ? $locale : 'en';

        return '/'.$locale.'/discord/guilds/'.$guild->guild_id;
    }
}
