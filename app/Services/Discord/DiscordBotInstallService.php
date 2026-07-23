<?php

namespace App\Services\Discord;

use App\Exceptions\DiscordBotInstallException;
use App\Models\DiscordGuild;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /** @var \Closure(float): void */
    private \Closure $sleep;

    public function __construct(
        ?string $baseUrl = null,
        ?string $botToken = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $redirectUri = null,
        ?\Closure $sleep = null,
    ) {
        $configured = is_string($u = config('services.discord.api_base_url')) ? $u : 'https://discord.com/api/v10';
        $this->baseUrl = rtrim($baseUrl ?? $configured, '/');
        $this->botToken = is_string($t = config('services.discord.bot_token')) ? $t : '';
        $this->clientId = $clientId ?? (is_string($c = config('services.discord.bot_client_id')) ? $c : '');
        $this->clientSecret = $clientSecret ?? (is_string($s = config('services.discord.bot_client_secret')) ? $s : '');
        $this->redirectUri = $redirectUri ?? (is_string($r = config('services.discord.bot_redirect_uri')) ? $r : '');
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    /**
     * Build the Discord OAuth2 "Add to Server" URL for a landlord.
     *
     * @param  int  $permissions  Discord permission integer the bot requests
     *                            on install (default: View Channels + Send
     *                            Messages + Embed Links + Read Message History).
     */
    public function installUrl(int $permissions = 3264174460928): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'scope' => 'bot applications.commands',
            'permissions' => $permissions,
            // disable_guild_select=false lets the landlord pick the guild;
            // we re-read the chosen guild from the access token post-exchange.
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
        ]);

        return self::OAUTH_AUTHORIZE_URL.'?'.$query;
    }

    /**
     * Complete the bot install from the OAuth2 authorization-code callback.
     *
     * Exchanges the code for a bot access token, confirms the install landed
     * in a guild via the guild-detail endpoint, then creates-or-updates the
     * {@see DiscordGuild} row with the roundup user as owner. Optionally
     * posts an onboarding message to the guild's system channel.
     *
     * @param  User  $landlord  The roundup user who clicked install; recorded
     *                          as discord_guilds.owner_user_id.
     * @return DiscordGuild The created-or-updated guild mapping.
     *
     * @throws DiscordBotInstallException on token-exchange or guild-fetch failure
     */
    public function completeInstall(User $landlord, string $code): DiscordGuild
    {
        $guildSnowflake = $this->exchangeCodeForGuildId($code);

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
            $id = isset($channel['id']) ? (string) $channel['id'] : null;
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
     * Exchange the authorization code for a bot access token and extract the
     * guild id the install landed in.
     *
     * Discord returns the chosen `guild_id` in the access-token response for
     * a bot install. The code is single-use.
     *
     * @throws DiscordBotInstallException on exchange failure or missing guild id
     */
    private function exchangeCodeForGuildId(string $code): string
    {
        if ($code === '') {
            throw DiscordBotInstallException::tokenExchangeFailed(0, 'empty authorization code');
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::OAUTH_TOKEN_URL, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                ]);
        } catch (ConnectionException $e) {
            throw DiscordBotInstallException::tokenExchangeFailed(0, 'connection: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw DiscordBotInstallException::tokenExchangeFailed($response->status(), $response->body());
        }

        $body = $response->json();
        $guildId = is_array($body) && isset($body['guild_id']) ? (string) $body['guild_id'] : null;

        if (! is_string($guildId) || $guildId === '') {
            // Discord's bot install flow always returns guild_id; its absence
            // means the response shape was unexpected (API drift / wrong scope).
            throw DiscordBotInstallException::tokenExchangeFailed($response->status(), 'missing guild_id in token response');
        }

        return $guildId;
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
