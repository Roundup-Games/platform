<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\ContentRemoved;
use App\Notifications\ContentReportWarning;
use App\Services\NotificationService;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Content moderation actions for Safety department content_report tickets.
 *
 * Extracted verbatim from ViewTicket's perform* methods: each action owns its
 * transaction, notification, and logging. UI definition (header actions) and
 * business logic live side by side per domain.
 */
final class ContentReport extends TicketAction
{
    /**
     * Header actions for Safety department content_report tickets. Returns []
     * for tickets outside the domain (the page assembles the array).
     *
     * @return array<int, Action>
     */
    public static function headerActions(Ticket $ticket): array
    {
        if (! self::isContentReport($ticket)) {
            return [];
        }

        $entityType = isset($ticket->metadata['entity_type']) ? self::asString($ticket->metadata['entity_type']) : null;
        $entityName = isset($ticket->metadata['entity_name']) ? self::asString($ticket->metadata['entity_name']) : 'this content';

        return [
            Action::make('dismissContentReport')
                ->label('Dismiss')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Dismiss Content Report')
                ->modalDescription('This will close the ticket with no action taken on the reported content.')
                ->modalSubmitActionLabel('Dismiss')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::dismissReport($ticket)),

            Action::make('warnUser')
                ->label('Warn User')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Warn User')
                ->modalDescription('This will close the ticket and send a warning notification to the content owner about community guidelines.')
                ->schema([
                    Textarea::make('warning_note')
                        ->label('Admin note (internal)')
                        ->placeholder('Optional internal note about this warning')
                        ->maxLength(1000),
                ])
                ->modalSubmitActionLabel('Send Warning')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn (array $data) => self::warnUser($ticket, $entityType, $entityName, $data['warning_note'] ?? null)),

            Action::make('clearCover')
                ->label('Clear Cover Image')
                ->icon(Heroicon::OutlinedPhoto)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Clear Cover Image')
                ->modalDescription("This removes ONLY the host-uploaded cover image on the reported {$entityType} — the {$entityType} itself stays published and falls back to its representative/default cover. The owner is notified. Use this instead of \"Remove Content\" when only the image is the issue.")
                ->modalSubmitActionLabel('Clear Cover')
                ->visible(function () use ($ticket, $entityType) {
                    // Proportionate takedown: only offer when the reported
                    // entity is a game/campaign that actually carries a
                    // host-uploaded cover (rung 1). Entities on the
                    // representative/default rung have nothing to clear.
                    if (! $ticket->isOpen() || ! in_array($entityType, ['game', 'campaign'], true)) {
                        return false;
                    }
                    $entity = self::resolveReportedEntity($ticket, $entityType);

                    return $entity !== null && method_exists($entity, 'hasCover') && $entity->hasCover();
                })
                ->action(fn () => self::clearCover($ticket, $entityType, $entityName)),

            Action::make('removeContent')
                ->label('Remove Content')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove Content')
                ->modalDescription("This will close the ticket, remove/hide the reported {$entityType}, and notify the content owner.")
                ->modalSubmitActionLabel('Remove')
                ->visible(fn () => $ticket->isOpen() && $entityType !== 'user')
                ->action(fn () => self::removeContent($ticket, $entityType, $entityName)),

            Action::make('suspendUser')
                ->label('Suspend User')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Suspend User Account')
                ->modalDescription('This will close the ticket, suspend the user account (is_disabled = true), and notify the user.')
                ->modalSubmitActionLabel('Suspend')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::suspendUser($ticket, $entityType)),

            Action::make('escalateContentReport')
                ->label('Escalate')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Escalate Content Report')
                ->modalDescription('This will reassign the ticket to a Platform Admin and increase priority to Urgent.')
                ->modalSubmitActionLabel('Escalate')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::escalateReport($ticket)),
        ];
    }

    /**
     * Dismiss content report: close ticket with no action on the reported entity.
     */
    public static function dismissReport(Ticket $ticket): void
    {
        try {
            $user = self::currentUser();
            if ($user === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            DB::transaction(function () use ($ticket, $user, $ticketService) {
                $ticketService->addNote($ticket, $user, 'Content report dismissed by admin — no action taken.');
                $ticketService->close($ticket, $user);
            });

            Log::info('content_report.dismissed', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'entity_type' => $ticket->metadata['entity_type'] ?? null,
                'entity_id' => $ticket->metadata['entity_id'] ?? null,
                'admin_id' => $user->id,
            ]);

            Notification::make()
                ->success()
                ->title('Report dismissed')
                ->body('The ticket has been closed with no action taken.')
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to dismiss content report', 'Dismiss failed', $e);
        }
    }

    /**
     * Warn user: close ticket, send warning notification to the content owner.
     */
    public static function warnUser(Ticket $ticket, ?string $entityType, ?string $entityName, ?string $note): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            $reportedUser = self::resolveReportedUser($ticket, $entityType);
            if (! $reportedUser) {
                Notification::make()
                    ->warning()
                    ->title('User not found')
                    ->body('Could not resolve the reported user. No warning sent.')
                    ->send();

                return;
            }

            $noteBody = 'Warning issued by admin.';
            if ($note) {
                $noteBody .= ' Note: '.$note;
            }

            DB::transaction(function () use ($ticket, $admin, $ticketService, $noteBody) {
                $ticketService->addNote($ticket, $admin, $noteBody);
                $ticketService->close($ticket, $admin);
            });

            // Send warning notification after transaction commits.
            // Routed through NotificationService so the dispatch is logged, the
            // recipient's channel preferences are honoured, and the block-list is
            // respected. getActor() returns null on ContentReportWarning, so the
            // block-list never suppresses these admin-issued notices anyway.
            $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
            app(NotificationService::class)->send(
                $reportedUser,
                new ContentReportWarning(
                    $entityType ?? 'content',
                    $entityName ?? 'reported content',
                    self::asString($reason),
                ),
                NotificationCategory::ModerationNotice,
            );

            Log::info('content_report.user_warned', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'warned_user_id' => $reportedUser->id,
                'entity_type' => $entityType,
                'entity_id' => $ticket->metadata['entity_id'] ?? null,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title('Warning sent')
                ->body("A warning notification has been sent to {$reportedUser->name}.")
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to warn user for content report', 'Warning failed', $e);
        }
    }

    /**
     * Clear ONLY the host-uploaded cover image on a reported game/campaign
     * (proportionate cover takedown). The entity itself stays published and
     * resolveCoverUrl() falls through to the representative/default rung.
     *
     * Reactive model: this is the cover-specific response the existing
     * ReportContent -> Safety ticket flow already surfaces. The reviewer sees
     * the cover in the ticket's cover-preview entry and can choose this lighter
     * action instead of canceling the whole entity via removeContent().
     * Mirrors removeContent()'s structure (transaction + note + close +
     * owner notify) but calls clearCoverImage() and scopes the notification so
     * the owner learns the cover specifically was removed.
     */
    public static function clearCover(Ticket $ticket, ?string $entityType, ?string $entityName): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            $entity = self::resolveReportedEntity($ticket, $entityType);
            if ($entity === null || ! method_exists($entity, 'clearCoverImage')) {
                Notification::make()
                    ->warning()
                    ->title('Entity not found')
                    ->body('Could not resolve the reported entity. No cover cleared.')
                    ->send();

                return;
            }

            $cleared = false;

            DB::transaction(function () use ($entity, $ticket, $admin, $ticketService, &$cleared) {
                $cleared = $entity->clearCoverImage();

                $ticketService->addNote($ticket, $admin, $cleared
                    ? 'Cover image cleared by admin; entity remains published.'
                    : 'Cover clear attempted but no host cover was present.');
                $ticketService->close($ticket, $admin);
            });

            if ($cleared) {
                $owner = self::resolveReportedUser($ticket, $entityType);
                if ($owner) {
                    $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
                    app(NotificationService::class)->send(
                        $owner,
                        new ContentRemoved(
                            $entityType ?? 'content',
                            $entityName ?? 'reported content',
                            self::asString($reason),
                            'cover_image',
                        ),
                        NotificationCategory::ModerationNotice,
                    );
                }
            }

            Log::info('content_report.cover_cleared', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'entity_type' => $entityType,
                'entity_id' => $ticket->metadata['entity_id'] ?? null,
                'cleared' => $cleared,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title($cleared ? 'Cover image cleared' : 'No cover to clear')
                ->body($cleared
                    ? 'The host-uploaded cover was removed; the entity stays published and now shows its fallback cover. The owner has been notified.'
                    : 'The reported entity had no host-uploaded cover to clear.')
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to clear cover for report', 'Clear cover failed', $e);
        }
    }

    /**
     * Remove content: close ticket, hide/remove the reported entity, notify owner.
     */
    public static function removeContent(Ticket $ticket, ?string $entityType, ?string $entityName): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            $entityId = $ticket->metadata['entity_id'] ?? null;
            $removed = false;

            // Remove content inside transaction so ticket close + content removal
            // are atomic. If either fails, everything rolls back.
            DB::transaction(function () use ($ticket, $admin, $ticketService, $entityType, $entityId, &$removed) {
                match ($entityType) {
                    'game' => $removed = self::removeGame($entityId !== null ? self::asString($entityId) : null),
                    'campaign' => $removed = self::removeCampaign($entityId !== null ? self::asString($entityId) : null),
                    default => $removed = false,
                };

                $ticketService->addNote($ticket, $admin, $removed
                    ? ucfirst((string) $entityType).' removed by admin.'
                    : 'Removal attempted but entity not found or already removed.');
                $ticketService->close($ticket, $admin);
            });

            // Notify the content owner
            if ($removed) {
                $reportedUser = self::resolveReportedUser($ticket, $entityType);
                if ($reportedUser) {
                    $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
                    app(NotificationService::class)->send(
                        $reportedUser,
                        new ContentRemoved(
                            $entityType ?? 'content',
                            $entityName ?? 'reported content',
                            self::asString($reason),
                        ),
                        NotificationCategory::ModerationNotice,
                    );
                }
            }

            Log::info('content_report.content_removed', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'removed' => $removed,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title($removed ? 'Content removed' : 'Entity not found')
                ->body($removed
                    ? 'The reported content has been removed and the owner has been notified.'
                    : 'The reported entity was not found or has already been removed.')
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to remove content for report', 'Remove failed', $e);
        }
    }

    /**
     * Suspend user: close ticket, disable the reported user account, notify user.
     */
    public static function suspendUser(Ticket $ticket, ?string $entityType): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            $reportedUser = self::resolveReportedUser($ticket, $entityType);
            if (! $reportedUser) {
                Notification::make()
                    ->warning()
                    ->title('User not found')
                    ->body('Could not resolve the reported user. No suspension applied.')
                    ->send();

                return;
            }

            // Suspend the user, note, and close — all atomic
            DB::transaction(function () use ($reportedUser, $ticket, $admin, $ticketService) {
                $reportedUser->update([
                    'is_disabled' => true,
                    'disabled_at' => now(),
                ]);

                $ticketService->addNote($ticket, $admin, "User account suspended ({$reportedUser->name}, ID: {$reportedUser->id}).");
                $ticketService->close($ticket, $admin);
            });

            // Send suspension notification after transaction commits.
            // Routed through NotificationService (like the sibling ContentReportWarning
            // below) so the dispatch is logged, the recipient's channel preferences are
            // honoured, and the PostHog delivery signal fires. getActor() returns null,
            // so the block-list never suppresses this admin-issued notice.
            $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
            app(NotificationService::class)->send(
                $reportedUser,
                new AccountSuspended(self::asString($reason)),
                NotificationCategory::ModerationNotice,
            );

            Log::info('content_report.user_suspended', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'suspended_user_id' => $reportedUser->id,
                'entity_type' => $entityType,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title('User suspended')
                ->body("{$reportedUser->name}'s account has been suspended and they have been notified.")
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to suspend user for content report', 'Suspension failed', $e);
        }
    }

    /**
     * Escalate content report: reassign to Platform Admin, increase priority to Urgent.
     */
    public static function escalateReport(Ticket $ticket): void
    {
        try {
            $user = self::currentUser();
            if ($user === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            $assignmentInfo = null;
            DB::transaction(function () use ($ticket, $user, $ticketService, &$assignmentInfo) {
                $ticketService->addNote($ticket, $user, "Content report escalated by {$user->name}.");
                $ticketService->changePriority($ticket, TicketPriority::Urgent, $user);

                $assignmentInfo = static::findPlatformAdminForEscalation($user);
                ['admin' => $platformAdmin] = $assignmentInfo;

                if ($platformAdmin->isNot($user)) {
                    // Deferred to afterCommit(): Ticket::assign() dispatches
                    // TicketAssigned, whose DispatchWebhook listener does a blocking
                    // Http::timeout(10) call. Running it inside the transaction
                    // would hold the row lock and risk send-on-rollback.
                    $admin = $platformAdmin;
                    DB::afterCommit(fn () => $ticket->assign($admin, $user));
                }
            });

            if ($assignmentInfo === null) {
                return;
            }
            ['admin' => $platformAdmin, 'assigned_name' => $assignedName] = $assignmentInfo;

            Log::info('content_report.escalated', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'escalated_by' => $user->id,
                'assigned_to' => $platformAdmin->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Report escalated')
                ->body("Priority set to Urgent and reassigned to {$assignedName}.")
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to escalate content report', 'Escalation failed', $e);
        }
    }

    /**
     * Resolve the reported user from the ticket metadata.
     * For user reports: directly from entity_id.
     * For game/campaign reports: from the entity's owner relationship.
     */
    private static function resolveReportedUser(Ticket $ticket, ?string $entityType): ?User
    {
        $entityId = $ticket->metadata['entity_id'] ?? null;

        return match ($entityType) {
            'user' => $entityId !== null ? User::find(self::asString($entityId)) : null,
            'game' => $entityId !== null ? Game::find(self::asString($entityId))?->owner : null,
            'campaign' => $entityId !== null ? Campaign::find(self::asString($entityId))?->owner : null,
            default => null,
        };
    }

    /**
     * Resolve the reported entity model itself (Game/Campaign/User) from ticket
     * metadata. Used by the cover-takedown flow (clearCover + the "Clear Cover
     * Image" action visibility check) to load the actual record carrying the
     * host-uploaded cover.
     *
     * @return Model|Game|Campaign|User|null
     */
    private static function resolveReportedEntity(Ticket $ticket, ?string $entityType)
    {
        $entityId = isset($ticket->metadata['entity_id']) ? self::asString($ticket->metadata['entity_id']) : null;
        if ($entityId === null || $entityId === '') {
            return null;
        }

        return match ($entityType) {
            'user' => User::find($entityId),
            'game' => Game::find($entityId),
            'campaign' => Campaign::find($entityId),
            default => null,
        };
    }

    /**
     * Remove a game by setting its status to canceled.
     */
    private static function removeGame(?string $entityId): bool
    {
        if (! $entityId) {
            return false;
        }

        $game = Game::find($entityId);
        if (! $game || $game->status === GameStatus::Canceled) {
            return false;
        }

        $game->update(['status' => GameStatus::Canceled]);

        return true;
    }

    /**
     * Remove a campaign by setting its status to cancelled.
     */
    private static function removeCampaign(?string $entityId): bool
    {
        if (! $entityId) {
            return false;
        }

        $campaign = Campaign::find($entityId);
        if (! $campaign || $campaign->status === CampaignStatus::Cancelled) {
            return false;
        }

        $campaign->update(['status' => CampaignStatus::Cancelled]);

        return true;
    }

    /**
     * Check if the ticket is a content report in the Safety department.
     */
    private static function isContentReport(Ticket $ticket): bool
    {
        return ($ticket->ticket_type ?? null) === 'content_report'
            && ($ticket->department->name ?? null) === 'Safety';
    }
}
