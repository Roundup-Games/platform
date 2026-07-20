<?php

namespace App\Filament\Pages\Reports;

use App\Enums\JoinSource;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only Filament report surfacing signup attribution signals.
 *
 * Mirrors {@see EventAttendanceReport} exactly: a plain {@see Page} that
 * implements {@see HasTable} and uses {@see InteractsWithTable}. Access is
 * gated by Filament's default admin auth via {@see canAccess()} (which calls
 * {@see User::isAdmin()}) — there is intentionally NO dedicated Policy, just
 * like the other Reports pages.
 *
 * Surfaces five write-once signup-attribution columns persisted on users by
 * S02/T01-T03 (signup_oauth_provider, first_touch_referer_domain,
 * first_touch_path, signup_content_type, signup_content_slug) plus a
 * participant-grain join_source breakdown from game_participants +
 * campaign_participants (separate grain — the two cannot be cleanly joined).
 *
 * The three user-grain summary breakdowns (provider, top referer domains, top
 * content types) are filter-aware: they read the table's filtered query via
 * {@see HasTable::getFilteredTableQuery()} so they stay in sync with whatever
 * provider / content-type / date filters the admin has applied. The
 * participant join_source breakdown is at a different grain and is shown
 * unfiltered.
 */
class SignupAttributionReport extends Page implements HasTable
{
    use InteractsWithTable;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-finger-print';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Reports';
    }

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.reports.signup-attribution-report';

    protected static ?string $title = 'Signup Attribution Report';

    protected static ?string $navigationLabel = 'Signup Attribution';

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Signed Up')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('signup_oauth_provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'google' => 'success',
                        'discord' => 'info',
                        'email' => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')
                    ->default('—')
                    ->sortable(),
                TextColumn::make('signup_content_type')
                    ->label('Content Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'game' => 'info',
                        'campaign' => 'success',
                        'venue' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('signup_content_slug')
                    ->label('Content Slug')
                    ->searchable()
                    ->limit(40)
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('first_touch_referer_domain')
                    ->label('Referer Domain')
                    ->searchable()
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('first_touch_path')
                    ->label('First-Touch Path')
                    ->searchable()
                    ->limit(50)
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('signup_oauth_provider')
                    ->label('Provider')
                    ->options([
                        'google' => 'Google',
                        'discord' => 'Discord',
                        'email' => 'Email',
                    ]),
                SelectFilter::make('signup_content_type')
                    ->label('Content Type')
                    ->options([
                        'game' => 'Game',
                        'campaign' => 'Campaign',
                        'venue' => 'Venue',
                    ]),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('signed_up_from')
                            ->label('Signed Up From'),
                        DatePicker::make('signed_up_until')
                            ->label('Signed Up Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['signed_up_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['signed_up_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Signup counts grouped by OAuth provider, respecting the active table
     * filters (provider / content-type / date range).
     *
     * @return array<string, int> Keys are provider labels ('Google', 'Discord',
     *                            'Email', 'Unknown'); values are signup counts.
     */
    public function providerBreakdown(): array
    {
        $rows = (clone $this->filteredUsersQuery())
            ->select('signup_oauth_provider', DB::raw('count(*) as total'))
            ->groupBy('signup_oauth_provider')
            ->pluck('total', 'signup_oauth_provider');

        // Explicit foreach over the Laravel Collection: the arrow callbacks
        // returned array<mixed> (the (int) casts were on mixed values) and
        // Collection::has() rejects null keys, so iterate and narrow with
        // is_numeric() before casting.
        $labels = ['google' => 'Google', 'discord' => 'Discord', 'email' => 'Email'];

        $result = [];
        foreach ($labels as $key => $label) {
            $value = $rows[$key] ?? null;
            $result[$label] = is_numeric($value) ? (int) $value : 0;
        }

        $unknown = 0;
        foreach ($rows as $key => $value) {
            if (is_string($key) && array_key_exists($key, $labels)) {
                continue;
            }
            $unknown += is_numeric($value) ? (int) $value : 0;
        }
        if ($unknown > 0) {
            $result['Unknown'] = $unknown;
        }

        return $result;
    }

    /**
     * Top-N referer domains by signup count, respecting the active table
     * filters. NULL referer domains are excluded — they carry no attribution
     * signal.
     *
     * @return array<string, int>
     */
    public function topRefererDomains(int $limit = 5): array
    {
        $rows = (clone $this->filteredUsersQuery())
            ->whereNotNull('first_touch_referer_domain')
            ->where('first_touch_referer_domain', '!=', '')
            ->select('first_touch_referer_domain', DB::raw('count(*) as total'))
            ->groupBy('first_touch_referer_domain')
            ->orderByDesc('total')
            ->orderBy('first_touch_referer_domain')
            ->limit($limit)
            ->pluck('total', 'first_touch_referer_domain');

        // foreach over the Collection: ->map(fn): int->toArray() is typed as
        // array<mixed> by Larastan (it does not propagate the int return),
        // so build the typed array directly with is_numeric narrowing.
        $result = [];
        foreach ($rows as $domain => $total) {
            if (! is_string($domain)) {
                continue;
            }
            $result[$domain] = is_numeric($total) ? (int) $total : 0;
        }

        return $result;
    }

    /**
     * Top-N signup content types by signup count, respecting the active table
     * filters.
     *
     * @return array<string, int>
     */
    public function topSignupContentTypes(int $limit = 5): array
    {
        $rows = (clone $this->filteredUsersQuery())
            ->whereNotNull('signup_content_type')
            ->where('signup_content_type', '!=', '')
            ->select('signup_content_type', DB::raw('count(*) as total'))
            ->groupBy('signup_content_type')
            ->orderByDesc('total')
            ->orderBy('signup_content_type')
            ->limit($limit)
            ->pluck('total', 'signup_content_type');

        $result = [];
        foreach ($rows as $type => $total) {
            if (! is_string($type)) {
                continue;
            }
            $result[ucfirst($type)] = is_numeric($total) ? (int) $total : 0;
        }

        return $result;
    }

    /**
     * Participant counts grouped by join_source across BOTH game_participants
     * and campaign_participants. Different grain from the user-grain signup
     * table — intentionally NOT narrowed by the user-attribution filters.
     *
     * @return array<string, int> Keys are localized join-source labels; the
     *                            'Unknown' bucket aggregates NULL/empty rows.
     */
    public function joinSourceBreakdown(): array
    {
        $select = fn (string $table) => DB::table($table)
            ->select('join_source', DB::raw('count(*) as total'))
            ->groupBy('join_source')
            ->pluck('total', 'join_source');

        $game = $select((new GameParticipant)->getTable());
        $campaign = $select((new CampaignParticipant)->getTable());

        // Combine per-source counts from both participant tables. mergeRecursive
        // produces per-key arrays when a key exists in both; sum those, else
        // narrow the scalar with is_numeric before casting (Larastan rejects
        // (int) mixed). foreach is used instead of ->map()->toArray() because
        // Larastan types the latter as array<mixed>.
        $combined = [];
        foreach ($game as $source => $total) {
            $combined[(string) $source] = is_numeric($total) ? (int) $total : 0;
        }
        foreach ($campaign as $source => $total) {
            $key = (string) $source;
            $add = is_numeric($total) ? (int) $total : 0;
            $combined[$key] = ($combined[$key] ?? 0) + $add;
        }

        $result = [];
        foreach (JoinSource::cases() as $source) {
            $result[$source->label()] = $combined[$source->value] ?? 0;
        }

        $unknown = $combined[''] ?? 0;
        if ($unknown > 0) {
            $result['Unknown'] = $unknown;
        }

        return $result;
    }

    /**
     * The user-grain query with all active table filters applied, used by the
     * three user-grain summary breakdowns so they stay in sync with the table.
     *
     * Falls back to a fresh {@see User::query()} when no filtered query is
     * available yet (e.g. during early render before the table boots).
     */
    /**
     * @return Builder<User>
     */
    protected function filteredUsersQuery(): Builder
    {
        $filtered = $this->getFilteredTableQuery();

        if ($filtered instanceof Builder) {
            return $filtered;
        }

        // getFilteredTableQuery() may return a Builder contract or null on the
        // very first render; guard by falling back to the base query.
        return User::query();
    }
}
