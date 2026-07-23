{{-- Discord Servers (landlord surface)

Lists the Discord guilds the current user installed the roundup bot into
(owner_user_id), with a link to each guild's settings page. Empty for
non-landlords — the whole section is gated on a non-empty $discordGuilds so
members who never installed a bot see nothing.

This closes the navigation dead-end: the install flow redirected here once,
then stranded the landlord with no way back. --}}
@if($discordGuilds->isNotEmpty())
    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-2 flex items-center gap-2">
            <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">dns</span>
            {{ __('settings.discord_servers_title') }}
        </h2>
        <p class="text-sm text-on-surface-variant mb-4">
            {{ __('settings.discord_servers_description') }}
        </p>

        <ul class="divide-y divide-outline-variant/30">
            @foreach($discordGuilds as $guild)
                <li class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="material-symbols-outlined text-xl text-on-surface-variant shrink-0" aria-hidden="true">forum</span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-on-surface truncate">{{ $guild->name }}</p>
                            <p class="text-xs {{ $guild->paused ? 'text-error' : 'text-on-surface-variant' }}">
                                @if($guild->paused)
                                    {{ __('settings.discord_servers_status_paused') }}
                                @else
                                    {{ __('settings.discord_servers_status_active') }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('discord.guild.settings', $guild->guild_id) }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary/10 rounded-lg transition-colors whitespace-nowrap">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">settings</span>
                        {{ __('settings.discord_servers_configure') }}
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
