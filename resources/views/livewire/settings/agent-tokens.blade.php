<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6" aria-labelledby="agent-tokens-heading">
    @php($presets = $this->presets)
    @php($agentCards = $this->agentCards)
    @php($canSelfIssueHost = $this->canSelfIssueHost)
    @php($mcpUrl = url('/mcp'))

    <h2 id="agent-tokens-heading" class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-2 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">smart_toy</span>
        {{ __('settings.title_agent_tokens') }}
    </h2>
    <p class="text-sm text-on-surface-variant mb-6">
        {{ __('settings.description_agent_tokens') }}
    </p>

    {{-- Freshly issued token: shown once; Done clears it out of component state too --}}
    @if($newToken)
        <div class="rounded-lg border border-outline-variant p-4 mb-6" x-data="copyButton()" role="status" aria-live="polite">
            <label for="agent-token-value" class="block text-xs font-medium text-on-surface-variant mb-1.5">
                {{ __('settings.label_agent_token_value') }}
            </label>
            <div class="flex items-stretch gap-2">
                <input id="agent-token-value" x-ref="tokenValue" type="text" readonly
                       value="{{ $newToken }}"
                       class="flex-1 min-w-0 rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface font-mono" />
                <button type="button" @click="copy($refs.tokenValue.value)"
                        class="inline-flex items-center gap-1.5 px-3 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container transition-colors text-sm font-medium whitespace-nowrap">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">content_copy</span>
                    <span x-show="!copied">{{ __('settings.action_calendar_feed_copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('common.status_copied') }}</span>
                </button>
                <button type="button" wire:click="dismissNewToken" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-3 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container transition-colors text-sm font-medium whitespace-nowrap">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                    <span>{{ __('settings.action_agent_token_done') }}</span>
                </button>
            </div>
            <p class="mt-2 text-xs text-error">
                {{ __('settings.hint_agent_token_value') }}
            </p>
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- PANEL 1 · CONNECT — presets, issuance, connection help (R098) --}}
    {{-- ============================================================ --}}
    <h3 class="text-sm font-heading font-semibold text-on-surface mb-3 flex items-center gap-2">
        <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">link</span>
        {{ __('settings.title_connect_agent') }}
    </h3>

    {{-- Ability presets --}}
    <fieldset>
        <legend class="block text-sm font-medium text-on-surface mb-2.5">
            {{ __('settings.label_agent_presets') }}
        </legend>
        <div class="grid gap-2.5 sm:grid-cols-3 mb-3">
            @foreach($presets as $presetCard)
                <button type="button"
                        wire:click="selectPreset('{{ $presetCard['value'] }}')"
                        @class([
                            'text-left rounded-xl border p-3 transition-colors',
                            'border-outline-variant hover:border-primary' => $preset !== $presetCard['value'] || $presetCard['locked'],
                            'border-primary bg-primary-container' => $preset === $presetCard['value'] && ! $presetCard['locked'],
                        ])
                        @if($presetCard['locked']) aria-disabled="true" @endif>
                    <span class="flex flex-wrap items-center gap-1.5 mb-1">
                        <span class="material-symbols-outlined text-base text-primary" aria-hidden="true"
                              >{{ $presetCard['icon'] }}</span>
                        <span class="text-sm font-medium text-on-surface">{{ $presetCard['label'] }}</span>
                        @if($presetCard['value'] === 'companion')
                            <span class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded bg-surface-container-high text-on-surface-variant whitespace-nowrap">{{ __('settings.label_agent_preset_read_only') }}</span>
                        @endif
                        @if($presetCard['locked'])
                            <span class="material-symbols-outlined text-sm text-on-surface-variant" aria-hidden="true">lock</span>
                        @endif
                    </span>
                    <span class="block text-xs text-on-surface-variant leading-relaxed">{{ $presetCard['description'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- GM perk locked-state hint (R097/R098): where the perk lives --}}
        @if(! $canSelfIssueHost)
            <p class="flex items-start gap-1.5 rounded-lg border border-outline-variant p-2.5 text-xs text-on-surface-variant mb-3">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">lock</span>
                <span>
                    {{ __('settings.hint_agent_ability_host_locked') }}
                    <a href="{{ route('membership') }}" class="text-primary font-medium hover:underline">{{ __('settings.action_agent_token_view_membership') }}</a>
                </span>
            </p>
        @endif
    </fieldset>

    {{-- Issuance form --}}
    <form wire:submit="createClient" class="space-y-4 mb-6">
        @error('name')
            <div class="rounded-lg bg-error-container p-4" role="alert">
                <p class="text-sm text-on-error-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">error</span>
                    {{ $message }}
                </p>
            </div>
        @enderror
        @error('abilities.*')
            <div class="rounded-lg bg-error-container p-4" role="alert">
                <p class="text-sm text-on-error-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">error</span>
                    {{ $message }}
                </p>
            </div>
        @enderror

        <div>
            <label for="agent-token-name" class="block text-sm font-medium text-on-surface mb-1.5">
                {{ __('settings.label_agent_token_name') }}
            </label>
            <input id="agent-token-name" type="text" wire:model="name"
                   placeholder="{{ __('settings.hint_agent_token_name') }}"
                   class="w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none" />
        </div>

        {{-- Advanced: raw ability checkboxes for custom sets --}}
        <details class="rounded-lg border border-outline-variant px-3 py-2">
            <summary class="text-xs font-medium text-on-surface-variant cursor-pointer">
                {{ __('settings.label_agent_advanced_abilities') }}
            </summary>
            <div class="space-y-2 pt-2">
                @php($availableAbilities = $this->availableAbilities)
                @foreach($availableAbilities as $ability)
                    <label class="flex items-start gap-2.5 p-2 rounded-lg hover:bg-surface-container transition-colors cursor-pointer">
                        <input type="checkbox" value="{{ $ability['value'] }}" wire:model="abilities"
                               class="mt-0.5 rounded border-outline text-primary focus:ring-primary" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-on-surface">{{ $ability['label'] }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ $ability['hint'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </details>

        <div class="flex justify-end">
            <button type="submit" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                <span class="material-symbols-outlined text-base" aria-hidden="true" wire:loading.remove wire:target="createClient">add</span>
                <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true" wire:loading wire:target="createClient" role="status">progress_activity</span>
                <span>{{ __('settings.action_agent_token_create') }}</span>
            </button>
        </div>
    </form>

    {{-- App-adapted connection help --}}
    <div class="rounded-xl border border-outline-variant p-4"
         x-data="{
             app: 'chatgpt',
             appTabs() { return [...this.$root.querySelectorAll('[role=tab]')] },
             focusAppTab(step) {
                 const tabs = this.appTabs();
                 const current = tabs.indexOf(document.activeElement);
                 const next = tabs[(current + step + tabs.length) % tabs.length];
                 next.focus();
                 next.click();
             },
         }">
        <p class="text-xs font-medium text-on-surface-variant mb-2.5">{{ __('settings.label_agent_app_picker') }}</p>
        <div class="flex flex-wrap gap-1.5 mb-4" role="tablist" aria-label="{{ __('settings.label_agent_app_picker') }}">
            @foreach($this->agentApps as $app)
                <button type="button" role="tab" id="agent-app-tab-{{ $app['value'] }}"
                        aria-controls="agent-app-panel-{{ $app['value'] }}"
                        @click="app = '{{ $app['value'] }}'"
                        @keydown.arrow-right.prevent="focusAppTab(1)"
                        @keydown.arrow-left.prevent="focusAppTab(-1)"
                        @keydown.home.prevent="appTabs()[0]?.focus()"
                        @keydown.end.prevent="appTabs().at(-1)?.focus()"
                        :aria-selected="app === '{{ $app['value'] }}'"
                        :tabindex="app === '{{ $app['value'] }}' ? 0 : -1"
                        :class="app === '{{ $app['value'] }}' ? 'bg-primary-container text-on-primary-container' : 'text-on-surface-variant hover:bg-surface-container'"
                        class="rounded-full px-3.5 py-1.5 text-xs font-medium transition-colors inline-flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $app['icon'] }}</span>
                    {{ $app['label'] }}
                </button>
            @endforeach
        </div>

        @foreach($this->agentApps as $app)
            <p role="tabpanel" id="agent-app-panel-{{ $app['value'] }}" aria-labelledby="agent-app-tab-{{ $app['value'] }}" tabindex="0"
               x-show="app === '{{ $app['value'] }}'" x-cloak class="text-sm text-on-surface-variant leading-relaxed mb-3">
                {{ $app['path'] }}
            </p>
        @endforeach

        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <span class="w-24 shrink-0 text-[11px] font-semibold uppercase tracking-wide text-on-surface-variant">{{ __('settings.label_agent_server_url') }}</span>
                <code class="flex-1 min-w-0 rounded-lg bg-surface-container px-3 py-2 text-xs font-mono text-on-surface overflow-x-auto whitespace-nowrap">{{ $mcpUrl }}</code>
                <button type="button" x-data="copyButton()" @click="copy(@js($mcpUrl))"
                        class="shrink-0 inline-flex items-center gap-1 px-2.5 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container transition-colors text-xs font-medium">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">content_copy</span>
                    <span x-show="!copied">{{ __('settings.action_calendar_feed_copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('common.status_copied') }}</span>
                </button>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-24 shrink-0 text-[11px] font-semibold uppercase tracking-wide text-on-surface-variant">{{ __('settings.label_agent_conn_token') }}</span>
                <code class="flex-1 min-w-0 rounded-lg bg-surface-container px-3 py-2 text-xs font-mono text-on-surface overflow-x-auto whitespace-nowrap">{{ $newToken ?? __('settings.content_agent_token_pending') }}</code>
            </div>
        </div>

        <div class="mt-4 rounded-lg bg-error-container/40 p-3 flex items-start gap-2">
            <span class="material-symbols-outlined text-base text-on-surface-variant" aria-hidden="true">cloud</span>
            <p class="text-xs text-on-surface-variant leading-relaxed">{{ __('settings.content_agent_cloud_warning') }}</p>
        </div>

        {{-- Developer path, de-emphasized (R098): coding agents self-serve --}}
        <details class="mt-3 rounded-lg border border-outline-variant px-3 py-2">
            <summary class="text-xs font-medium text-on-surface-variant cursor-pointer flex items-center gap-1.5">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">code</span>
                {{ __('settings.label_agent_dev_accordion') }}
            </summary>
            <p class="text-xs text-on-surface-variant leading-relaxed mt-2">{{ __('settings.content_agent_dev_note') }}</p>
            <pre class="mt-2 rounded-lg bg-on-surface text-surface px-3 py-2.5 text-[11px] leading-relaxed overflow-x-auto"><code>claude mcp add --transport http roundup {{ $mcpUrl }} \
  --header "Authorization: Bearer $ROUNDUP_MCP_TOKEN"</code></pre>
            <p class="text-xs text-on-surface-variant mt-2">
                <a href="{{ route('agents.markdown') }}" class="text-primary font-medium hover:underline">{{ __('settings.content_agent_catalog_note') }}</a>
            </p>
        </details>
    </div>

    {{-- ============================================================ --}}
    {{-- PANEL 2 · YOUR AGENTS — per-agent servicing cards (R098) --}}
    {{-- ============================================================ --}}
    <h3 class="text-sm font-heading font-semibold text-on-surface mt-8 mb-1 flex items-center gap-2">
        <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">psychology</span>
        {{ __('settings.title_your_agents') }}
    </h3>
    <p class="text-xs text-on-surface-variant mb-4">{{ __('settings.content_your_agents_lede') }}</p>

    @if(count($agentCards) === 0)
        <p class="text-sm text-on-surface-variant py-4 text-center">
            {{ __('settings.content_your_agents_empty') }}
        </p>
    @else
        <div class="space-y-3">
            @foreach($agentCards as $card)
                @php($client = $this->clients->firstWhere('id', $card['id']))
                <div class="rounded-xl border border-outline-variant p-4 @if($card['revoked']) opacity-75 @endif" wire:key="agent-card-{{ $card['id'] }}">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center shrink-0"
                              aria-hidden="true">
                            @if($card['revoked'])
                                <span class="material-symbols-outlined text-base">block</span>
                            @else
                                <span class="material-symbols-outlined text-base">bolt</span>
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $card['name'] }}</p>
                                <span class="shrink-0 inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded-full
                                    {{ $card['revoked'] ? 'bg-surface-container-high text-on-surface-variant' : 'bg-primary-container text-on-primary-container' }}">
                                    {{ $card['revoked'] ? __('settings.status_agent_token_revoked') : __('settings.status_agent_token_active') }}
                                </span>
                            </div>
                            <p class="text-xs text-on-surface-variant mt-0.5">
                                {{ __('settings.label_agent_connected_on', ['date' => format_date($card['created_at'])]) }} &middot;
                                {{ __('settings.label_agent_token_last_used') }}: {{ $this->lastUsedAt($client) }}
                            </p>
                        </div>
                    </div>

                    {{-- Plain-language permissions (R098) --}}
                    @if(count($card['abilities']) > 0)
                        <div class="flex flex-wrap gap-1.5 mt-3">
                            @foreach($card['abilities'] as $abilityValue)
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-surface-container text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[13px]" aria-hidden="true">{{ $this->abilityIcon($abilityValue) }}</span>
                                    {{ $this->abilityChip($abilityValue) }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Honest usage stats from the audit trail --}}
                    <div class="grid grid-cols-3 gap-2 mt-3">
                        <div class="rounded-lg bg-surface-container px-3 py-2">
                            <p class="text-base font-semibold text-on-surface">{{ $card['today'] }}</p>
                            <p class="text-[11px] text-on-surface-variant">{{ __('settings.label_agent_stat_calls_today') }}</p>
                        </div>
                        <div class="rounded-lg bg-surface-container px-3 py-2">
                            <p class="text-base font-semibold text-on-surface">{{ $card['week'] }}</p>
                            <p class="text-[11px] text-on-surface-variant">{{ __('settings.label_agent_stat_calls_week') }}</p>
                        </div>
                        <div class="rounded-lg bg-surface-container px-3 py-2">
                            <p class="text-base font-semibold text-on-surface">{{ $card['tools'] }}</p>
                            <p class="text-[11px] text-on-surface-variant">{{ __('settings.label_agent_stat_tools_used') }}</p>
                        </div>
                    </div>

                    {{-- Recent activity feed (dispatched calls only) --}}
                    @if(count($card['activity']) > 0)
                        <div class="mt-3 border-l-2 border-outline-variant pl-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-on-surface-variant mb-1">{{ __('settings.heading_agent_recent_activity') }}</p>
                            @foreach($card['activity'] as $row)
                                <p class="text-xs text-on-surface-variant py-0.5 flex items-baseline gap-2">
                                    <span class="font-medium text-on-surface">{{ $row['label'] }}</span>
                                    <span class="ml-auto whitespace-nowrap">{{ format_date($row['at'], 'short_date') }}</span>
                                </p>
                            @endforeach
                            <p class="text-[11px] text-on-surface-variant mt-1">{{ __('settings.content_agent_audit_footnote') }}</p>
                        </div>
                    @endif

                    {{-- Dormancy hint (R098) --}}
                    @if($card['dormant'])
                        <p class="mt-3 rounded-lg bg-surface-container px-3 py-2 text-xs text-on-surface-variant flex items-start gap-1.5">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">schedule</span>
                            {{ __('settings.hint_agent_dormant') }}
                        </p>
                    @endif

                    @if(! $card['revoked'])
                        <div class="flex justify-end mt-3">
                            <button wire:click="revokeClient('{{ $card['id'] }}')" wire:loading.attr="disabled"
                                    wire:confirm="{{ __('settings.confirm_agent_token_revoke') }}"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-error/40 text-error rounded-lg hover:bg-error-container/50 transition-colors text-xs font-medium">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">block</span>
                                {{ __('settings.action_agent_token_revoke') }}
                            </button>
                        </div>
                    @else
                        <p class="text-[11px] text-on-surface-variant mt-3">{{ __('settings.content_agent_revoked_clean_slate') }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-xs text-on-surface-variant mt-4">
            {{ __('settings.content_your_agents_footnote') }}
        </p>
    @endif

    {{-- ============================================================ --}}
    {{-- PANEL 3 · SAFETY (R098) --}}
    {{-- ============================================================ --}}
    <h3 class="text-sm font-heading font-semibold text-on-surface mt-8 mb-3 flex items-center gap-2">
        <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">shield</span>
        {{ __('settings.title_agent_safety') }}
    </h3>
    <div class="space-y-2">
        @foreach($this->safetyRows as $row)
            <p class="flex items-start gap-2 text-xs text-on-surface-variant leading-relaxed">
                <span class="material-symbols-outlined text-sm text-primary shrink-0" aria-hidden="true">{{ $row['icon'] }}</span>
                {{ $row['text'] }}
            </p>
        @endforeach
    </div>
</section>
