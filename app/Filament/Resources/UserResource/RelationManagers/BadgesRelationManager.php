<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Dto\BadgeAwardResult;
use App\Enums\BadgeSource;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\BadgeService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Per-user badge operations (D186, spec §2.3): the badge shelf, manual
 * grant and revocation, all with a required reason.
 *
 * Every mutation dispatches to BadgeService — this manager never writes
 * badge rows itself, so each change keeps the audited pipeline and its
 * badge_events trail (RoutesParticipantTransitions pattern). Definition
 * curation (retirement, display overrides) stays on
 * BadgeDefinitionResource; a hold is corrected in the context of the
 * user it belongs to.
 */
class BadgesRelationManager extends RelationManager
{
    protected static string $relationship = 'badges';

    protected static ?string $title = 'Badges';

    public function table(Table $table): Table
    {
        return $table
            // Eager-load the per-row relations the columns, revoke-modal
            // heading, and action handlers touch (badgeDefinition, and
            // BadgeService::revoke's $record->user) — without it every
            // paginated row lazy-loads its definition.
            ->modifyQueryUsing(fn ($query) => $query->with(['badgeDefinition', 'user']))
            ->columns([
                TextColumn::make('badgeDefinition.name_key')
                    ->label('Badge')
                    // EN admin surface — the registry's name key resolves
                    // through __() the same way the definition list does
                    // (K002-exempt). Not searchable/sortable: the stored
                    // value is the machine key (badges.*_name), so admin
                    // text searches would miss and sorts would order by
                    // key string — filter by definition instead.
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : __($state)),
                TextColumn::make('tier')
                    ->badge()
                    ->formatStateUsing(fn (BadgeUser $record): string => $record->tier ?? 'Base')
                    ->sortable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (BadgeUser $record): string => ucfirst($record->source->value))
                    ->sortable(),
                // Revocation supersedes lapse (BadgeService semantics) —
                // a row can never be both, one column tells the story.
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->getStateUsing(fn (BadgeUser $record): ?string => $record->revoked_at !== null
                        ? 'Revoked'
                        : ($record->lapsed_at !== null ? 'Lapsed' : null))
                    ->color(fn (string $state): string => $state === 'Revoked' ? 'danger' : 'warning')
                    ->tooltip(fn (BadgeUser $record): ?string => $record->revoked_reason),
                TextColumn::make('first_awarded_at')
                    ->label('First awarded')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_awarded_at')
                    ->label('Last awarded')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('first_awarded_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    // Badge mutation is an admin-only capability, not an
                    // incidental of panel admission (same idiom as the
                    // comp actions in UserResource): a Service Admin who
                    // can reach the user table must not thereby gain
                    // honor-revoking power. Revocation is sticky against
                    // the nightly sweep — reversal is an explicit manual
                    // re-grant (BadgeService semantics).
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('danger')
                    ->modalHeading(fn (BadgeUser $record): string => "Revoke [{$record->badgeDefinition->key}]")
                    ->modalDescription('The hold stays visible with a Revoked state — the nightly sweep will not restore it; reversing is an explicit re-grant.')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000)
                            ->rows(3)
                            ->helperText('Recorded on the audit trail with your account.'),
                    ])
                    ->action(function (BadgeUser $record, array $data): void {
                        // Server-side twin of the visible() gate above —
                        // Livewire can invoke an action without re-running
                        // visibility (defense in depth, comp idiom).
                        if (auth()->user()?->isAdmin() !== true) {
                            return;
                        }

                        $revoked = app(BadgeService::class)->revoke(
                            $record->user,
                            (string) $record->badgeDefinition->key,
                            (string) $data['reason'],
                            auth()->user(),
                        );

                        if (! $revoked) {
                            Notification::make()
                                ->info()
                                ->title("[{$record->badgeDefinition->key}] is not held or already revoked")
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title("[{$record->badgeDefinition->key}] revoked")
                            ->send();
                    }),
            ])
            ->headerActions([
                Action::make('grantBadge')
                    ->label('Grant badge')
                    // Admin-only grant (comp idiom): a manual grant is a
                    // permanent, never-lapsing honor — the same tier of
                    // power as comp membership.
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->modalHeading('Grant badge')
                    ->modalDescription('Manual award with a recorded reason. Retired and dormant badges are not selectable. A manual grant is never lapsed by the nightly sweep — removing it is an explicit revocation.')
                    ->form([
                        Select::make('badge')
                            ->label('Badge')
                            ->required()
                            ->searchable()
                            ->live()
                            // Tierless badge + stray tier state cannot be
                            // submitted: hidden fields skip validation, so
                            // the pair is guarded here — the tier select is
                            // hidden for tierless badges and its own state
                            // would otherwise ride along unchecked.
                            ->rules([
                                fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $tier = $get('tier');

                                    if ($tier === null) {
                                        return;
                                    }

                                    if (self::tierOptions($value) === []) {
                                        $fail('The selected badge has no tiers — clear the tier selection.');
                                    }
                                },
                            ])
                            ->options(fn (): array => BadgeDefinition::query()
                                ->where('is_active', true)
                                ->where('is_dormant', false)
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn (BadgeDefinition $definition): array => [
                                    $definition->key => __($definition->name_key),
                                ])
                                ->all()),
                        Select::make('tier')
                            ->label('Tier')
                            ->options(fn (Get $get): array => self::tierOptions($get('badge')))
                            ->visible(fn (Get $get): bool => self::tierOptions($get('badge')) !== [])
                            ->helperText('Leave empty for the base award of a tiered badge.')
                            ->rules([
                                fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if ($value === null) {
                                        return;
                                    }

                                    $options = self::tierOptions($get('badge'));

                                    if ($options !== [] && (! is_string($value) || ! array_key_exists($value, $options))) {
                                        $fail('The selected tier does not belong to the selected badge.');
                                    }
                                },
                            ]),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000)
                            ->rows(3)
                            ->helperText('Mandatory for manual awards — recorded on the audit trail with your account.'),
                    ])
                    ->action(function (RelationManager $livewire, array $data): void {
                        // Server-side twin of the visible() gate above.
                        if (auth()->user()?->isAdmin() !== true) {
                            return;
                        }

                        $user = $livewire->getOwnerRecord();

                        if (! $user instanceof User) {
                            return;
                        }

                        $result = app(BadgeService::class)->award(
                            $user,
                            (string) $data['badge'],
                            filled($data['tier'] ?? null) ? (string) $data['tier'] : null,
                            null,
                            BadgeSource::Manual,
                            auth()->user(),
                            (string) $data['reason'],
                        );

                        self::notifyAwardResult($result, (string) $data['badge'], $data['tier'] ?? null);
                    }),
            ]);
    }

    /**
     * Tier options for the selected badge key, from the synced
     * definition's tiers map (key => threshold; thresholds order tiers,
     * keys are the values). Empty for tierless badges — the select is
     * hidden and stray selections are refused by the field rule.
     *
     * @return array<string, string>
     */
    private static function tierOptions(mixed $badgeKey): array
    {
        if (! is_string($badgeKey) || $badgeKey === '') {
            return [];
        }

        /** @var BadgeDefinition|null $definition */
        $definition = BadgeDefinition::query()->where('key', $badgeKey)->first();

        if ($definition === null) {
            return [];
        }

        // orderedTierKeys(): threshold-ascending — the registry order,
        // not jsonb's canonicalized key order (see the accessor).
        return collect($definition->orderedTierKeys())
            ->mapWithKeys(fn (string $tier): array => [$tier => str($tier)->replace('_', ' ')->title()->toString()])
            ->all();
    }

    /**
     * Map the engine's outcome to the admin notification (Awarded /
     * Upgraded are moments, AlreadyHeld is routine, refusals warn).
     */
    private static function notifyAwardResult(BadgeAwardResult $result, string $key, ?string $tier): void
    {
        $title = match ($result) {
            BadgeAwardResult::Awarded => "Badge [{$key}] granted",
            BadgeAwardResult::Upgraded => "Badge [{$key}] upgraded to tier [{$tier}]",
            BadgeAwardResult::AlreadyHeld => "Badge [{$key}] is already held — nothing to do",
            BadgeAwardResult::RefusedInactive => "Badge [{$key}] is retired — new awards are refused",
            BadgeAwardResult::RefusedDormant => "Badge [{$key}] is dormant — its signal has not shipped yet",
            BadgeAwardResult::RefusedRevoked => "Badge [{$key}] was revoked — re-grant explicitly to reverse",
        };

        $notification = match (true) {
            $result === BadgeAwardResult::Awarded, $result === BadgeAwardResult::Upgraded => Notification::make()->success(),
            $result === BadgeAwardResult::AlreadyHeld => Notification::make()->info(),
            default => Notification::make()->warning(),
        };

        $notification->title($title)->send();
    }
}
