@php
    /** @var array<string, mixed> $metadata */
    /** @var \Escalated\Laravel\Models\Ticket $ticket */
    /** @var \App\Services\TicketPayloadRenderer $renderer */

    $ticketType = $ticket->ticket_type;
    $renderer = app(\App\Services\TicketPayloadRenderer::class);

    // Helper to render an entity link (extracted from PHP string concat)
    $renderEntityLink = function (array $entity) use ($renderer): string {
        $name = e($entity['name'] ?? $entity['id'] ?? __('Unknown'));
        $type = $entity['type'] ?? 'unknown';
        $id = $entity['id'] ?? null;

        if ($id && $url = $renderer->resolveEntityUrl($type, $id)) {
            return '<a href="'.e($url).'" class="text-primary hover:underline font-medium">'.$name.'</a>'
                .' <span class="text-on-surface-variant text-xs font-mono">'.e($id).'</span>';
        }

        return $name;
    };
@endphp

{{-- ── Game System Request ── --}}
@if ($ticketType === 'game_system_request')
    @php
        $name = $metadata['game_system_name'] ?? $ticket->subject;
        $type = is_string($metadata['game_system_type'] ?? null) ? $metadata['game_system_type'] : null;
        $bggUrl = $metadata['bgg_url'] ?? null;
        // Only http(s) URLs may ever become a link href. filter_var (Laravel's `url`
        // rule) accepts the javascript:// scheme, which would be a stored-XSS vector
        // when an admin clicks it. Non-safe / non-string values render as text.
        $bggUrlSafe = is_string($bggUrl) && \Illuminate\Support\Str::startsWith($bggUrl, ['http://', 'https://']);
        $publisher = $metadata['publisher'] ?? null;
        $designer = $metadata['designer'] ?? null;
        $notes = $metadata['details'] ?? $ticket->description;
        $linkedSystemId = $metadata['game_system_id'] ?? null;
    @endphp
    @if ($linkedSystemId)
        <div class="flex items-center gap-2 mb-3 px-3 py-2 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
            <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-lg">check_circle</span>
            <span class="text-sm font-medium text-green-700 dark:text-green-300">{{ __('Game system has been added and is now available.') }}</span>
        </div>
    @endif
    <div class="rounded-lg border border-outline-variant overflow-hidden">
        <div class="px-4 py-3 bg-surface-container-low border-b border-outline-variant">
            <span class="text-sm font-semibold text-on-surface">{{ __('Request Details') }}</span>
        </div>
        <div class="px-4 py-3 space-y-3">
            <div class="flex items-start gap-3">
                <span class="text-sm text-on-surface-variant min-w-24">{{ __('Game System') }}</span>
                <span class="text-sm font-medium text-on-surface">{{ $name }}</span>
            </div>
            @if ($type)
                @php
                    $typeLabel = match ($type) {
                        'boardgame' => __('Board Game'),
                        'ttrpg' => __('Tabletop RPG'),
                        default => ucfirst($type),
                    };
                @endphp
                <div class="flex items-start gap-3">
                    <span class="text-sm text-on-surface-variant min-w-24">{{ __('Type') }}</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ $typeLabel }}</span>
                </div>
            @endif
            @if ($bggUrl)
                <div class="flex items-start gap-3">
                    <span class="text-sm text-on-surface-variant min-w-24">{{ __('BGG URL') }}</span>
                    @if ($bggUrlSafe)
                        <a href="{{ $bggUrl }}" target="_blank" rel="noopener noreferrer" class="text-sm text-primary hover:underline break-all">{{ $bggUrl }} <span class="material-symbols-outlined text-xs align-middle">open_in_new</span></a>
                    @else
                        {{-- Non-http(s) URLs (e.g. javascript:) are never emitted as an href to
                            prevent stored XSS. The value is still shown, auto-escaped, as text. --}}
                        <span class="text-sm text-on-surface-variant break-all">{{ $bggUrl }}</span>
                    @endif
                </div>
            @endif
            @if ($publisher)
                <div class="flex items-start gap-3">
                    <span class="text-sm text-on-surface-variant min-w-24">{{ __('Publisher') }}</span>
                    <span class="text-sm text-on-surface">{{ $publisher }}</span>
                </div>
            @endif
            @if ($designer)
                <div class="flex items-start gap-3">
                    <span class="text-sm text-on-surface-variant min-w-24">{{ __('Designer') }}</span>
                    <span class="text-sm text-on-surface">{{ $designer }}</span>
                </div>
            @endif
            @if ($notes)
                <div class="flex items-start gap-3 pt-2 border-t border-outline-variant">
                    <span class="text-sm text-on-surface-variant min-w-24">{{ __('Notes') }}</span>
                    <p class="text-sm text-on-surface whitespace-pre-wrap">{{ $notes }}</p>
                </div>
            @endif
        </div>
    </div>

{{-- ── Content Report ── --}}
@elseif ($ticketType === 'content_report')
    @if (isset($metadata['reason']))
        <div class="flex items-start gap-3 mb-3">
            <span class="text-sm text-on-surface-variant min-w-24">{{ __('Reason') }}</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">{{ $renderer->reasonLabel($metadata['reason']) }}</span>
        </div>
    @endif
    @if (! empty($metadata['entities']) && is_array($metadata['entities']))
        @php $entity = $metadata['entities'][0] ?? null; @endphp
        @if (is_array($entity))
            <div class="flex items-start gap-3 mb-3">
                <span class="text-sm text-on-surface-variant min-w-24">{{ $renderer->entityTypeLabel($entity['type'] ?? 'unknown') }}</span>
                <span class="text-sm">{!! $renderEntityLink($entity) !!}</span>
            </div>
        @endif
    @endif
    @if (isset($metadata['context']) && is_array($metadata['context']) && ! empty($metadata['context']))
        @include('components.escalated._ticket-context', ['context' => $metadata['context']])
    @endif
    @if (! empty($metadata['details']))
        <div class="mt-3 pt-3 border-t border-outline-variant">
            <span class="text-sm font-medium text-on-surface-variant">{{ __('Details') }}</span>
            <p class="mt-1 text-sm text-on-surface">{{ $metadata['details'] }}</p>
        </div>
    @endif

{{-- ── Review Report (has reported_user section) ── --}}
@elseif ($ticketType === 'review_report')
    @if (isset($metadata['reason']))
        <div class="flex items-start gap-3 mb-3">
            <span class="text-sm text-on-surface-variant min-w-24">{{ __('Reason') }}</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">{{ $renderer->reasonLabel($metadata['reason']) }}</span>
        </div>
    @endif
    @if (isset($metadata['reported_user']))
        @php $reportedUser = is_array($metadata['reported_user']) ? $metadata['reported_user'] : []; @endphp
        <div class="flex items-start gap-3 mb-3">
            <span class="text-sm text-on-surface-variant min-w-24">{{ __('Review author') }}</span>
            <span class="text-sm">{!! $renderEntityLink($reportedUser) !!}</span>
        </div>
    @endif
    @if (! empty($metadata['details']))
        <div class="mt-3 pt-3 border-t border-outline-variant">
            <span class="text-sm font-medium text-on-surface-variant">{{ __('Details') }}</span>
            <p class="mt-1 text-sm text-on-surface">{{ $metadata['details'] }}</p>
        </div>
    @endif

{{-- ── Billing Support ── --}}
@elseif ($ticketType === 'billing_support')
    @if (isset($metadata['reason']))
        <div class="flex items-start gap-3 mb-3">
            <span class="text-sm text-on-surface-variant min-w-24">{{ __('Issue type') }}</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ $renderer->reasonLabel($metadata['reason']) }}</span>
        </div>
    @endif
    @if (isset($metadata['context']) && is_array($metadata['context']) && ! empty($metadata['context']))
        <div class="rounded-lg border border-outline-variant overflow-hidden mb-3">
            <div class="px-4 py-2 bg-surface-container-low border-b border-outline-variant">
                <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">{{ __('Subscription Info') }}</span>
            </div>
            <div class="px-4 py-3">
                @include('components.escalated._ticket-context', ['context' => $metadata['context']])
            </div>
        </div>
    @endif
    @if (! empty($metadata['details']))
        <div class="mt-3 pt-3 border-t border-outline-variant">
            <span class="text-sm font-medium text-on-surface-variant">{{ __('Details') }}</span>
            <p class="mt-1 text-sm text-on-surface">{{ $metadata['details'] }}</p>
        </div>
    @endif

{{-- ── Account Support / Data Export ── --}}
@elseif (in_array($ticketType, ['account_recovery', 'data_export_request']))
    @if (isset($metadata['reason']))
        <div class="flex items-start gap-3 mb-3">
            <span class="text-sm text-on-surface-variant min-w-24">{{ __('Issue type') }}</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ $renderer->reasonLabel($metadata['reason']) }}</span>
        </div>
    @endif
    @if (! empty($metadata['details']))
        <div class="mt-3 pt-3 border-t border-outline-variant">
            <span class="text-sm font-medium text-on-surface-variant">{{ __('Details') }}</span>
            <p class="mt-1 text-sm text-on-surface">{{ $metadata['details'] }}</p>
        </div>
    @endif

{{-- ── Generic fallback ── --}}
@else
    @if (isset($metadata['actor']))
        @php $actor = $metadata['actor']; @endphp
        @if (is_array($actor))
            @php
                $actorName = e($actor['name'] ?? __('Unknown'));
                if (isset($actor['id']) && ($actor['type'] ?? null) === 'user') {
                    $actorUrl = $renderer->resolveEntityUrl('user', $actor['id']);
                    if ($actorUrl) {
                        $actorName = '<a href="'.e($actorUrl).'" class="text-primary hover:underline font-medium">'.$actorName.'</a>';
                    }
                }
            @endphp
            <div class="mb-2"><span class="font-medium text-on-surface-variant">{{ __('Reported by') }}:</span> {!! $actorName !!}</div>
        @endif
    @endif
    @if (isset($metadata['reason']))
        <div class="mb-2"><span class="font-medium text-on-surface-variant">{{ __('Reason') }}:</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">{{ $renderer->reasonLabel($metadata['reason']) }}</span>
        </div>
    @endif
    @if (! empty($metadata['entities']) && is_array($metadata['entities']))
        <div class="mb-2">
            <span class="font-medium text-on-surface-variant">{{ __('Affected entities') }}:</span>
            <ul class="mt-1 space-y-1">
                @foreach ($metadata['entities'] as $entity)
                    @if (is_array($entity))
                        <li class="flex items-center gap-2 text-sm">
                            <span class="text-on-surface-variant">{{ $renderer->entityTypeLabel($entity['type'] ?? 'unknown') }}:</span>
                            {!! $renderEntityLink($entity) !!}
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif
    @if (isset($metadata['context']) && is_array($metadata['context']) && ! empty($metadata['context']))
        @include('components.escalated._ticket-context', ['context' => $metadata['context']])
    @endif
    @if (! empty($metadata['details']))
        <div class="mt-3 pt-3 border-t border-outline-variant">
            <span class="text-sm font-medium text-on-surface-variant">{{ __('Details') }}</span>
            <p class="mt-1 text-sm text-on-surface">{{ $metadata['details'] }}</p>
        </div>
    @endif
@endif
