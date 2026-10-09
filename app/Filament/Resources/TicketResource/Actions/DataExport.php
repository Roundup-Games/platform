<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Http\Controllers\ExportDownloadController;
use App\Models\User;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Data export actions for data_export_request tickets.
 *
 * Extracted verbatim from ViewTicket's perform* methods: the action owns its
 * artisan export run, the signed download URL, the transaction, notifications,
 * and logging.
 */
final class DataExport extends TicketAction
{
    /**
     * Header actions for data_export_request tickets that are still open.
     * Returns [] for tickets outside the domain (the page assembles the array).
     *
     * @return array<int, Action>
     */
    public static function headerActions(Ticket $ticket): array
    {
        if (($ticket->ticket_type ?? null) !== 'data_export_request') {
            return [];
        }

        return [
            Action::make('generateDataExport')
                ->label('Generate Data Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Generate Data Export')
                ->modalDescription(function () use ($ticket) {
                    $requesterName = $ticket->requester->name ?? $ticket->guest_name ?? 'Unknown User';

                    return "This will generate a full data export for {$requesterName}. The export will be attached as a reply with a download link, and the ticket will be resolved.";
                })
                ->modalSubmitActionLabel('Generate Export')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::generate($ticket)),
        ];
    }

    /**
     * Generate a user data export, reply to the ticket with a signed download
     * URL, and resolve.
     */
    public static function generate(Ticket $ticket): void
    {
        $requester = $ticket->requester;

        if (! $requester instanceof User) {
            Notification::make()
                ->danger()
                ->title('Cannot generate export')
                ->body('The ticket requester is not a registered user.')
                ->send();

            return;
        }

        $admin = self::currentUser();
        if ($admin === null) {
            return;
        }
        $ticketService = app(TicketService::class);

        try {
            // Step 1: Generate the export by running the artisan command
            $exitCode = Artisan::call('export:user-data', [
                'user' => $requester->id,
            ]);

            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                throw new \RuntimeException('Export command failed: '.$output);
            }

            // The command outputs info lines followed by the stored path as the last line.
            // Extract only the final line to avoid including progress messages in the path.
            $lines = array_filter(explode("\n", $output));
            $storedPath = end($lines);

            if (empty($storedPath) || ! str_starts_with($storedPath, 'exports/')) {
                throw new \RuntimeException('Export command did not return a valid file path. Output: '.$output);
            }

            // Step 2: Generate a signed download URL with file token (valid for 7 days)
            // The token binds the URL to this specific export file, preventing stale
            // signed URLs from serving a different (newer) export.
            $downloadUrl = URL::signedRoute(
                'export.download',
                [
                    'user' => $requester->id,
                    'token' => ExportDownloadController::deriveFileToken($storedPath),
                ],
                now()->addDays(7),
            );

            // Step 3: Create a reply with the download link
            $fileSize = Storage::disk('local')->size($storedPath);
            $fileSizeFormatted = format_bytes($fileSize);

            $replyBody = "Your data export is ready for download.\n\n"
                ."**File:** `user-data-{$requester->id}.zip` ({$fileSizeFormatted})\n"
                ."**Download link:** [Download your data]({$downloadUrl})\n\n"
                .'This link will expire in 7 days. If you need a new link, please reply to this ticket.';

            DB::transaction(function () use ($ticket, $admin, $ticketService, $replyBody, $storedPath) {
                // Add reply with signed download URL
                $ticketService->reply($ticket, $admin, $replyBody);

                // Add internal note with file path for audit
                $ticketService->addNote($ticket, $admin, "Data export generated. File: {$storedPath}");

                // Store export path in ticket metadata for direct download resolution
                $ticket->update(['metadata' => array_merge($ticket->metadata ?? [], [
                    'export_path' => $storedPath,
                    'export_generated_at' => now()->toIso8601String(),
                ])]);

                // Resolve the ticket
                $ticketService->resolve($ticket, $admin);
            });

            Log::info('data_export.generated_and_delivered', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'user_id' => $requester->id,
                'file_path' => $storedPath,
                'file_size' => $fileSize,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title('Data export generated')
                ->body("The data export for {$requester->name} has been generated and delivered via ticket reply.")
                ->send();

        } catch (\Throwable $e) {
            Log::error('data_export.generation_failed', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'user_id' => $requester->id,
                'error' => $e->getMessage(),
                'admin_id' => $admin->id,
            ]);

            // Add internal note about the failure but don't resolve the ticket
            try {
                $ticketService->addNote($ticket, $admin, "Data export generation failed: {$e->getMessage()}");
            } catch (\Throwable $noteException) {
                Log::error('data_export.failed_to_add_note', [
                    'ticket_id' => $ticket->id,
                    'error' => $noteException->getMessage(),
                ]);
            }

            Notification::make()
                ->danger()
                ->title('Export generation failed')
                ->body($e->getMessage())
                ->send();
        }
    }
}
