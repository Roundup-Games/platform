<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Models\TicketSubjectLink;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * One-shot backfill: create TicketSubjectLink rows for legacy tickets whose
 * entity references still live only in ticket metadata.
 *
 * Background: Ticket Subjects (escalated-laravel v1.5.0) were adopted on
 * 2026-06-27 as the first-class polymorphic link between a ticket and the
 * host-app entities it is about. Tickets created BEFORE that commit carry
 * their entity refs only as metadata JSON keys (entity_type/entity_id,
 * review_id/review_author_id, location_id, game_system_id). This command
 * reads those keys, resolves the model instance, and attaches it as a
 * subject so legacy tickets become queryable via $ticket->subjects and
 * Ticket::whereHas('subjects', ...).
 *
 * Mirrors the live creation paths exactly — attaches via Ticket::attachSubject()
 * so morph aliases (game/campaign/game_system) are honored identically to new
 * tickets, and the role labels match (reported/author/venue/created).
 *
 * Idempotent: skips any ticket that already has at least one subject, and
 * attachSubject() itself is unique on (ticket_id, subject_type, subject_id).
 * Safe to re-run. Tickets whose referenced model was deleted are skipped with
 * a warning (the metadata stays intact for audit).
 */
class BackfillTicketSubjects extends Command
{
    protected $signature = 'tickets:backfill-subjects
                            {--batch=200 : Number of tickets to process per chunk}
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Attach ticket subjects from legacy metadata for tickets created before the Ticket Subjects integration';

    /**
     * Metadata entity_type slug -> model class. Mirrors
     * App\Livewire\Reports\ReportContent::ENTITY_MODELS.
     */
    private const ENTITY_TYPE_TO_MODEL = [
        'user' => User::class,
        'game' => Game::class,
        'campaign' => Campaign::class,
    ];

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        // Tickets of the entity-bearing types that don't yet have any subject.
        // Once a ticket has even one subject it's considered migrated.
        $entityTicketTypes = [
            'content_report',
            'review_report',
            'venue_claim',
            'venue_proposal',
            'game_system_request',
        ];

        $query = Ticket::whereIn('ticket_type', $entityTicketTypes)
            ->whereDoesntHave('subjects')
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('All entity-bearing tickets already have subjects. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} legacy ticket(s) without subjects.");
        if ($dryRun) {
            $this->warn('Dry run mode — no changes will be made.');
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $attached = 0;
        $skipped = 0;

        // chunkById so updates inside the loop cannot desync the cursor.
        $query->chunkById($batchSize, function ($tickets) use ($dryRun, $bar, &$attached, &$skipped) {
            foreach ($tickets as $ticket) {
                $plan = $this->planAttachments($ticket);

                if ($plan === []) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                foreach ($plan as [$model, $role]) {
                    if ($dryRun) {
                        $this->line("  Ticket {$ticket->id} ({$ticket->ticket_type}) -> {$model->getMorphClass()}:".(is_int($k = $model->getKey()) || is_string($k) ? (string) $k : '')." [{$role}]");
                    } else {
                        $ticket->attachSubject($model, $role);
                    }
                }

                $attached++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info($dryRun
            ? "Would attach subjects to {$attached} ticket(s); skipped {$skipped} with no resolvable entity."
            : "Attached subjects to {$attached} ticket(s); skipped {$skipped} with no resolvable entity."
        );

        return self::SUCCESS;
    }

    /**
     * Resolve a ticket's metadata to a list of [Model, role] pairs to attach.
     *
     * Returns an empty array when the ticket has no resolvable entity (the
     * referenced model was deleted, or the metadata keys are absent).
     *
     * @return array<int, array{0: Model, 1: string}>
     */
    private function planAttachments(Ticket $ticket): array
    {
        $metadata = $ticket->metadata;

        if (! is_array($metadata)) {
            return [];
        }

        return match ($ticket->ticket_type) {
            'content_report' => $this->planContentReport($metadata),
            'review_report' => $this->planReviewReport($metadata),
            'venue_claim', 'venue_proposal' => $this->planVenue($metadata),
            'game_system_request' => $this->planGameSystemRequest($metadata),
            default => [],
        };
    }

    /**
     * Content report: metadata['entity_type'] + ['entity_id'] -> one subject.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, array{0: Model, 1: string}>
     */
    private function planContentReport(array $metadata): array
    {
        $type = $metadata['entity_type'] ?? null;
        $id = $metadata['entity_id'] ?? null;

        if (! is_string($type) || ! isset(self::ENTITY_TYPE_TO_MODEL[$type])) {
            return [];
        }

        $model = self::ENTITY_TYPE_TO_MODEL[$type]::find($id);

        return $model instanceof Model ? [[$model, 'reported']] : [];
    }

    /**
     * Review report: review_id + review_author_id -> Review + author User.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, array{0: Model, 1: string}>
     */
    private function planReviewReport(array $metadata): array
    {
        $plan = [];

        $review = isset($metadata['review_id'])
            ? Review::find($metadata['review_id'])
            : null;
        if ($review instanceof Model) {
            $plan[] = [$review, 'reported'];
        }

        $author = isset($metadata['review_author_id'])
            ? User::find($metadata['review_author_id'])
            : null;
        if ($author instanceof Model) {
            $plan[] = [$author, 'author'];
        }

        return $plan;
    }

    /**
     * Venue claim/proposal: location_id -> Location (only if it exists; venue
     * proposals approved before the integration recorded it, unapproved ones
     * never created a Location and are correctly skipped).
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, array{0: Model, 1: string}>
     */
    private function planVenue(array $metadata): array
    {
        $locationId = $metadata['location_id'] ?? null;
        if ($locationId === null) {
            return [];
        }

        $location = Location::find($locationId);

        return $location instanceof Model ? [[$location, 'venue']] : [];
    }

    /**
     * Game-system request: game_system_id -> GameSystem (only set after BGG
     * sync or manual create; unresolved requests have no GameSystem and are
     * correctly skipped).
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, array{0: Model, 1: string}>
     */
    private function planGameSystemRequest(array $metadata): array
    {
        $gameSystemId = $metadata['game_system_id'] ?? null;
        if ($gameSystemId === null) {
            return [];
        }

        $gameSystem = GameSystem::find($gameSystemId);

        return $gameSystem instanceof Model ? [[$gameSystem, 'created']] : [];
    }
}
