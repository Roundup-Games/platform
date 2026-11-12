<x-filament-panels::page>
    @if(! $trend['hasData'])
        <x-filament::section>
            <x-filament::card>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No Core Web Vitals measurements stored yet. The weekly check runs Mondays 05:00
                    (<code class="text-xs">cwv:check</code>) — run it manually to seed the first run. History
                    accumulates one run per week; the trend view becomes more meaningful with every run.
                </p>
            </x-filament::card>
        </x-filament::section>
    @else
        @php($runLabels = \App\Filament\Pages\System\CoreWebVitalsPage::runLabels($trend['runs']))
        @php($threshold = (int) config('cwv.thresholds.performance_score'))

        {{-- ── KPI row ─────────────────────────────────────────── --}}
        <x-filament::section>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Checks ≥ {{ $threshold }}</p>
                    <p class="text-2xl font-semibold">{{ $trend['kpis']['passing'] }}<span class="text-base text-gray-400"> / {{ $trend['kpis']['total'] }}</span></p>
                    <p class="text-xs {{ ($trend['kpis']['prevPassing'] ?? 0) <= $trend['kpis']['passing'] ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400' }}">
                        {{ $trend['kpis']['prevPassing'] }} previous run
                    </p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Desktop average</p>
                    <p class="text-2xl font-semibold text-blue-600 dark:text-blue-400">{{ $trend['kpis']['desktopAvg'] }}</p>
                    @if($trend['kpis']['prevDesktopAvg'] !== null)
                        <p class="text-xs text-gray-400">{{ $trend['kpis']['prevDesktopAvg'] }} previous run · lab score</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Mobile average</p>
                    <p class="text-2xl font-semibold text-purple-600 dark:text-purple-400">{{ $trend['kpis']['mobileAvg'] }}</p>
                    @if($trend['kpis']['prevMobileAvg'] !== null)
                        <p class="text-xs text-gray-400">{{ $trend['kpis']['prevMobileAvg'] }} previous run · lab score</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Needs attention</p>
                    <p class="text-2xl font-semibold {{ $trend['kpis']['worst'] ? \App\SEO\CwvTrendService::band($trend['kpis']['worst']['score'])['text'] : '' }}">{{ $trend['kpis']['worst']['template'] ?? '—' }}</p>
                    @if($trend['kpis']['worst'])
                        <p class="text-xs text-gray-400">{{ $trend['kpis']['worst']['strategy'] }} · {{ strtoupper($trend['kpis']['worst']['locale']) }} · {{ $trend['kpis']['worst']['score'] }} — worst of {{ $trend['kpis']['total'] }}</p>
                    @endif
                </div>
            </div>

            {{-- heat grid: every check, latest run, one cell --}}
            <div class="mt-5 flex flex-wrap items-center gap-4">
                <p class="w-28 shrink-0 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">All {{ $trend['kpis']['total'] }} checks<br>latest run</p>
                @foreach($trend['templates'] as $key => $template)
                    <div class="text-center">
                        <p class="mb-1 text-[10px] text-gray-500 dark:text-gray-400">{{ $template['label'] }}</p>
                        <div class="flex gap-1">
                            <div class="mr-1 flex flex-col justify-around text-[9px] text-gray-400"><span>dk</span><span>mb</span></div>
                            <div class="grid grid-cols-2 gap-1">
                                @foreach(['desktop', 'mobile'] as $heatStrategy)
                                    @foreach(config('cwv.locales') as $heatLocale)
                                        @php($heat = collect($trend['checks'])->first(fn ($c) => $c['template'] === $key && $c['strategy'] === $heatStrategy && $c['locale'] === $heatLocale))
                                        @if($heat)
                                            @php($heatBand = \App\SEO\CwvTrendService::band($heat['score']))
                                            <button type="button" wire:click="selectTemplate('{{ $key }}')" {{ $selectedTemplate === $key ? 'aria-current="true"' : '' }}
                                                class="flex h-6 w-6 items-center justify-center rounded text-[9px] font-bold {{ $heatBand['bg'] }} {{ $heatBand['text'] }} hover:ring-1 hover:ring-gray-400"
                                                title="{{ $template['label'] }} · {{ $heatStrategy }} · {{ strtoupper($heatLocale) }} — {{ $heat['score'] }} ({{ $heat['date'] }})">
                                                {{ $heat['score'] }}
                                            </button>
                                        @else
                                            <span class="flex h-6 w-6 items-center justify-center rounded border border-dashed border-gray-300 text-[9px] text-gray-400 dark:border-gray-700">—</span>
                                        @endif
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- ── template cards: parallel desktop/mobile lanes ──── --}}
        <x-filament::section>
            <x-slot:heading>Templates — desktop and mobile over time</x-slot:heading>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($trend['templates'] as $key => $template)
                    <button type="button" wire:click="selectTemplate('{{ $key }}')"
                        class="rounded-xl border {{ $selectedTemplate === $key ? 'border-blue-500' : 'border-gray-200 dark:border-gray-700' }} bg-white p-4 text-left transition hover:border-blue-500 dark:bg-gray-900"
                        aria-haspopup="section">
                        <div class="mb-3 flex items-baseline justify-between gap-2">
                            <div>
                                <p class="text-sm font-bold">{{ $template['label'] }}</p>
                                <p class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $template['path'] }}</p>
                            </div>
                            <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold {{ $template['band']['bg'] }} {{ $template['band']['text'] }}">{{ $template['band']['label'] }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach(['desktop', 'mobile'] as $laneStrategy)
                                @php($lane = $template['lanes'][$laneStrategy])
                                @continue($lane['latest'] === null)
                                @php($laneBand = \App\SEO\CwvTrendService::band($lane['latest']['score']))
                                <div class="rounded-lg border border-gray-200 p-2.5 dark:border-gray-700">
                                    <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <span class="h-2 w-2 rounded-full {{ $laneStrategy === 'desktop' ? 'bg-blue-500' : 'bg-purple-500' }}"></span>
                                        {{ $laneStrategy }}
                                    </p>
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-3xl font-extrabold {{ $laneBand['text'] }}">{{ $lane['latest']['score'] }}</span>
                                        @if($lane['latest']['prev'] !== null && $lane['latest']['prev'] !== $lane['latest']['score'])
                                            <span class="rounded px-1.5 py-0.5 text-xs font-bold {{ $lane['latest']['score'] > $lane['latest']['prev'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400' }}">
                                                {{ $lane['latest']['score'] > $lane['latest']['prev'] ? '▲' : '▼' }} {{ abs($lane['latest']['score'] - $lane['latest']['prev']) }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-[11px] text-gray-400">worst of EN {{ $lane['latest']['en'] }} / DE {{ $lane['latest']['de'] }}</p>
                                    {!! \App\SEO\CwvScoreChart::render($lane['series'], ['width' => 300, 'height' => 46, 'runs' => $trend['runs']]) !!}
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">Click for history · {{ count($trend['runs']) }} runs since {{ $runLabels[0] }}</p>
                    </button>
                @endforeach
            </div>
        </x-filament::section>

        {{-- ── drill-down for the selected template ─────────────── --}}
        @if($detail !== null)
            <x-filament::section>
                <x-slot:heading>{{ $detail['label'] }} — history</x-slot:heading>
                <x-slot:aside>
                    <div class="flex items-center gap-2">
                        <div class="inline-flex overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700" role="group" aria-label="Locale filter">
                            @foreach(['all' => 'All', 'en' => 'EN', 'de' => 'DE'] as $locValue => $locLabel)
                                <button type="button" wire:click="setLocaleFilter('{{ $locValue }}')" aria-pressed="{{ $localeFilter === $locValue ? 'true' : 'false' }}"
                                    class="px-3 py-1 text-xs font-semibold {{ $localeFilter === $locValue ? 'bg-blue-600 text-white' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}">
                                    {{ $locLabel }}
                                </button>
                            @endforeach
                        </div>
                        <button type="button" wire:click="clearTemplate()" class="rounded-lg border border-gray-200 px-3 py-1 text-xs text-gray-500 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Close</button>
                    </div>
                </x-slot:aside>

                <p class="mb-4 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $detail['path'] }} · {{ implode(' + ', $detail['locales']) }} representative URLs</p>

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach(config('cwv.strategies') as $detailStrategy)
                        @php($detailLane = $detail['strategies'][$detailStrategy])
                        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                            <p class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <span class="h-2.5 w-2.5 rounded-full {{ $detailStrategy === 'desktop' ? 'bg-blue-500' : 'bg-purple-500' }}"></span>
                                {{ $detailStrategy }} — latest {{ $detailLane['latest'] }}
                                <span class="rounded-full px-2 py-0.5 text-[11px] {{ $detailLane['band']['bg'] }} {{ $detailLane['band']['text'] }}">{{ $detailLane['band']['label'] }}</span>
                            </p>
                            {!! \App\SEO\CwvScoreChart::render($detailLane['series'], ['width' => 460, 'height' => 190, 'axes' => true, 'runs' => $trend['runs']]) !!}
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Run</th>
                                @foreach(config('cwv.strategies') as $thStrategy)
                                    @foreach($detail['locales'] as $thLocale)
                                        <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">{{ ucfirst($thStrategy) }} {{ strtoupper($thLocale) }}</th>
                                    @endforeach
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($detail['table'] as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M') }}@if($row['latest']) <span class="text-[10px] text-gray-400">(latest)</span>@endif</td>
                                    @foreach($row['cells'] as $cell)
                                        @if($cell !== null)
                                            @php($cellBand = \App\SEO\CwvTrendService::band($cell))
                                            <td class="px-3 py-2 text-right font-bold {{ $cellBand['text'] }}">{{ $cell }}</td>
                                        @else
                                            <td class="px-3 py-2 text-right text-gray-400">—</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- field-data tiles: real values once CrUX includes the site, honest awaiting state until then --}}
                <div class="mt-5 rounded-xl border border-dashed border-gray-300 p-4 dark:border-gray-700">
                    <p class="text-sm font-bold">Real-visitor vitals — LCP · INP · CLS (CrUX field data, p75 per device)</p>
                    @if($detail['fieldState'] === 'none')
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Not yet available: roundup.games has not met Chrome UX Report's real-traffic inclusion threshold, so every check so far returned <code>field_data_state: none</code>. These tiles activate automatically once PSI begins returning field percentiles — thresholds below are Google's good/needs-improvement/poor bands.</p>
                    @else
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Latest CrUX field percentiles, worst locale per device — Google's good/needs-improvement/poor bands.</p>
                    @endif
                    @php($fieldSpecs = [
                        'lcp_ms' => ['title' => 'LCP — loading', 'fmt' => fn($v) => number_format($v / 1000, 1).'s', 'bands' => 'good ≤ 2.5s · needs ≤ 4s · poor > 4s'],
                        'inp_ms' => ['title' => 'INP — responsiveness', 'fmt' => fn($v) => $v.'ms', 'bands' => 'good ≤ 200ms · needs ≤ 500ms · poor > 500ms'],
                        'cls' => ['title' => 'CLS — stability', 'fmt' => fn($v) => number_format($v, 3), 'bands' => 'good ≤ 0.1 · needs ≤ 0.25 · poor > 0.25'],
                    ])
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        @foreach($fieldSpecs as $metric => $spec)
                            @php($desktopField = $detail['field']['desktop'] ?? null)
                            @php($mobileField = $detail['field']['mobile'] ?? null)
                            @php($hasField = ($desktopField[$metric] ?? null) !== null || ($mobileField[$metric] ?? null) !== null)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700 {{ $hasField ? '' : 'opacity-75' }}">
                                <p class="text-xs font-bold">{{ $spec['title'] }}</p>
                                @if($hasField)
                                    <div class="mt-1.5 space-y-1">
                                        @foreach(['desktop', 'mobile'] as $fieldStrategy)
                                            @php($fieldValue = ($detail['field'][$fieldStrategy][$metric] ?? null))
                                            @if($fieldValue !== null)
                                                @php($fieldBand = \App\SEO\CwvTrendService::fieldBand($metric, $fieldValue))
                                                <p class="flex items-center gap-2 text-xs">
                                                    <span class="h-2 w-2 rounded-full {{ $fieldStrategy === 'desktop' ? 'bg-blue-500' : 'bg-purple-500' }}"></span>
                                                    <span class="uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $fieldStrategy }}</span>
                                                    <span class="font-bold {{ $fieldBand['text'] }}">{{ $spec['fmt']($fieldValue) }}</span>
                                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $fieldBand['bg'] }} {{ $fieldBand['text'] }}">{{ $fieldBand['label'] }}</span>
                                                </p>
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ $spec['bands'] }}</p>
                                    <p class="mt-1.5 text-[11px] italic text-gray-400">awaiting visitor data…</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- ── legend + lab/field honesty note ─────────────────── --}}
        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            <span class="mr-3"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm align-middle" style="background:#10b981"></span>good ≥ 90</span>
            <span class="mr-3"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm align-middle" style="background:#84cc16"></span>watch {{ $threshold }}–89 (meets threshold)</span>
            <span class="mr-3"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm align-middle" style="background:#f59e0b"></span>needs work 50–{{ $threshold - 1 }}</span>
            <span class="mr-3"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm align-middle" style="background:#ef4444"></span>poor &lt; 50</span>
            <span class="mr-3"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full align-middle" style="background:#3b82f6"></span>EN</span>
            <span class="mr-3"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full align-middle" style="background:#ec4899"></span>DE</span>
            <span>dashed = alert threshold ({{ $threshold }})</span>
        </p>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Scores are Lighthouse <strong>lab</strong> measurements from the PageSpeed Insights API on fixed representative URLs — repeatable and deploy-comparable, not CrUX. Gaps between points are real: a missed weekly run shows as a wider step, never interpolated.
        </p>
    @endif
</x-filament-panels::page>
