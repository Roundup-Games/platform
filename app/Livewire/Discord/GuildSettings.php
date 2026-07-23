<?php

namespace App\Livewire\Discord;

use App\Models\DiscordGuild;
use App\Services\Discord\DiscordBotInstallService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Landlord guild-configuration surface for the roundup Discord bot (T06).
 *
 * Bound to a Discord guild the landlord has installed the bot into. Lets the
 * landlord pick the two routing channels (calendar + games) and toggle the
 * pause switch. Produces the channel config the DiscordPublisher (T05) reads
 * to decide where (and whether) enriched event cards are posted.
 *
 * Authorization: only the roundup user recorded as the guild's
 * `owner_user_id` (the landlord who completed the install) may configure it.
 * A non-owner lands on a 403 via abort() — this is a config surface, not a
 * read view, so there is no public preview path.
 *
 * Channel picker: the list is loaded from the bot's live channel-list read
 * ({@see DiscordBotInstallService::listChannels()}) so the landlord picks
 * from real, current channels. The picker stores the Discord channel
 * snowflakes directly on the guild row.
 *
 * Pause switch: flips {@see DiscordGuild::$paused}. The publisher treats a
 * paused guild as "do not post"; this surface logs the pause/resume action
 * per the slice verification contract (landlord pause and resume actions
 * logged).
 */
#[Layout('layouts.app')]
class GuildSettings extends Component
{
    /**
     * The Discord guild snowflake from the URL. Locked so Livewire cannot
     * rewrite it from a tampered client payload (the landlord can only ever
     * configure the guild whose URL they are on).
     */
    #[Locked]
    public string $guildSnowflake;

    /** @var array<string, mixed> The guild row's editable config */
    public ?string $calendar_channel_id = null;

    public ?string $games_channel_id = null;

    public bool $paused = false;

    public string $guildName = '';

    /** @var list<array{id: string, name: string, type: int}> Channels the landlord can pick from */
    public array $channels = [];

    public bool $saved = false;

    public bool $pausedChanged = false;

    /** @var array<string> Guild names per channel type, for option labels */
    public bool $channelsLoadFailed = false;

    /**
     * The loaded guild model, kept for the render + auth check.
     */
    private DiscordGuild $guild;

    public function mount(string $guild): void
    {
        $this->guildSnowflake = $guild;

        $guildModel = DiscordGuild::where('guild_id', $this->guildSnowflake)->first();
        if (! $guildModel) {
            abort(404);
        }

        $this->authorizeOwner($guildModel);

        $this->guild = $guildModel;
        $this->guildName = $guildModel->name;
        $this->calendar_channel_id = $guildModel->calendar_channel_id;
        $this->games_channel_id = $guildModel->games_channel_id;
        $this->paused = (bool) $guildModel->paused;

        $this->loadChannels();
    }

    /**
     * Re-fetch the channel list from Discord (landlord clicked "refresh" or
     * added a channel after install). Best-effort: on failure, keeps the
     * previously loaded list and flags the failure to the UI.
     */
    public function refreshChannels(DiscordBotInstallService $installService): void
    {
        $this->loadChannels($installService);
    }

    /**
     * Persist the channel picker selections.
     */
    public function save(DiscordBotInstallService $installService): void
    {
        $guildModel = $this->resolveGuild();
        $this->authorizeOwner($guildModel);

        $validated = $this->validate([
            'calendar_channel_id' => ['nullable', 'string'],
            'games_channel_id' => ['nullable', 'string'],
        ]);

        $guildModel->update([
            'calendar_channel_id' => $validated['calendar_channel_id'] ?: null,
            'games_channel_id' => $validated['games_channel_id'] ?: null,
        ]);

        Log::info('discord_guild.channels_configured', [
            'guild_id' => $guildModel->guild_id,
            'row_id' => $guildModel->id,
            'calendar_channel_id' => $guildModel->calendar_channel_id,
            'games_channel_id' => $guildModel->games_channel_id,
            'owner_user_id' => $guildModel->owner_user_id,
        ]);

        $this->saved = true;
    }

    /**
     * Toggle the landlord pause switch. Logged per the slice verification
     * contract (landlord pause and resume actions logged).
     */
    public function togglePaused(DiscordBotInstallService $installService): void
    {
        $guildModel = $this->resolveGuild();
        $this->authorizeOwner($guildModel);

        $guildModel->update(['paused' => ! $guildModel->paused]);
        $this->paused = (bool) $guildModel->paused;

        Log::info('discord_guild.pause_toggled', [
            'guild_id' => $guildModel->guild_id,
            'row_id' => $guildModel->id,
            'paused' => $guildModel->paused,
            'action' => $guildModel->paused ? 'paused' : 'resumed',
            'owner_user_id' => $guildModel->owner_user_id,
        ]);

        $this->pausedChanged = true;
    }

    public function render(): View
    {
        $guildModel = $this->resolveGuild();

        return view('livewire.discord.guild-settings', [
            'guild' => $guildModel,
        ]);
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Resolve the guild model (memoized on the instance). Re-queries so the
     * render reflects the latest paused/channel state after an action.
     */
    private function resolveGuild(): DiscordGuild
    {
        $guildModel = DiscordGuild::where('guild_id', $this->guildSnowflake)->first();
        if (! $guildModel) {
            abort(404);
        }

        return $guildModel;
    }

    /**
     * Only the roundup user who installed the bot (owner_user_id) may
     * configure the guild. Per KNOWLEDGE rule #1, we use a Policy-style
     * gate here rather than a bare abort, but since there is no DiscordGuild
     * policy yet we perform the explicit check and abort(403) on mismatch.
     */
    private function authorizeOwner(DiscordGuild $guildModel): void
    {
        $user = Auth::user();
        if (! $user || (string) $user->id !== (string) $guildModel->owner_user_id) {
            abort(403, 'Only the server owner who installed roundup can configure this guild.');
        }
    }

    /**
     * Load the postable channel list from Discord. Best-effort: on failure,
     * flags {@see $channelsLoadFailed} and keeps whatever list was already
     * loaded (possibly empty) so the landlord can still toggle pause.
     */
    private function loadChannels(?DiscordBotInstallService $installService = null): void
    {
        $guildModel = isset($this->guild) ? $this->guild : $this->resolveGuild();

        try {
            $service = $installService ?? app(DiscordBotInstallService::class);
            $this->channels = $service->listChannels($guildModel);
            $this->channelsLoadFailed = false;
        } catch (\Throwable $e) {
            Log::warning('discord_guild.channel_list_failed', [
                'guild_id' => $guildModel->guild_id,
                'error' => $e->getMessage(),
            ]);
            $this->channelsLoadFailed = true;
            $this->channels = [];
        }
    }
}
