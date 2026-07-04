<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\VenueType;
use App\Filament\Resources\GameSystemResource;
use App\Filament\Resources\TicketResource;
use App\Http\Controllers\ExportDownloadController;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\ContentRemoved;
use App\Notifications\ContentReportWarning;
use App\Services\BggSyncService;
use App\Services\GameSystemRequestService;
use App\Services\VenueClaimService;
use App\Services\VenueProposalService;
use Escalated\Filament\Resources\TicketResource\Pages\ViewTicket as BaseViewTicket;
use Escalated\Laravel\Contracts\TicketSubject;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Models\TicketSubjectLink;
use Escalated\Laravel\Services\TicketService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Custom ViewTicket page extending the Escalated vendor ViewTicket.
 *
 * Adds game-system-specific actions for BGG sync on tickets in the
 * Game Systems department with ticket_type=game_system_request:
 *
 * - "Sync from BGG" — syncs GameSystem using bgg_url from ticket metadata
 * - "Search BGG" — searches BGG, previews data, and optionally syncs
 *
 * Adds review moderation actions for Safety department review_report tickets:
 *
 * - "Dismiss Report" — closes ticket, keeps review published
 * - "Remove Review" — closes ticket, hides review
 * - "Escalate" — reassigns to Platform Admin, increases priority to Urgent
 *
 * Adds content moderation actions for Safety department content_report tickets:
 *
 * - "Dismiss" — closes ticket, no action taken on reported content
 * - "Warn User" — closes ticket, sends warning notification to content owner
 * - "Remove Content" — closes ticket, removes/hides the reported entity
 * - "Suspend User" — closes ticket, suspends the reported user account
 * - "Escalate" — reassigns to Platform Admin, increases priority to Urgent
 *
 * Adds venue proposal actions for Events department venue_proposal tickets:
 *
 * - "Approve Venue" — creates/updates a verified Location, resolves ticket
 * - "Reject Venue" — resolves ticket with a reason, no Location changes
 *
 * Adds venue claim actions for Events department venue_claim tickets:
 *
 * - "Approve Claim" — assigns management of an existing Location to the
 *   claimant (sets managed_by), resolves ticket. Address is never changed.
 * - "Reject Claim" — resolves ticket with a reason, no Location changes
 */
class ViewTicket extends BaseViewTicket
{
    protected static string $resource = TicketResource::class;

    /**
     * BGG search results stored in component state.
     *
     * @var array<int, array{bgg_id: int, name: string, year_released: int|null, bgg_type: string}>
     */
    public array $bggSearchResults = [];

    /**
     * The selected BGG ID from search results.
     */
    public ?int $selectedBggId = null;

    /**
     * The selected BGG item name (for display).
     */
    public ?string $selectedBggName = null;

    /**
     * Full BGG thing data for the selected game, fetched for preview.
     *
     * @var array<string, mixed>|null
     */
    public ?array $bggPreviewData = null;

    /**
     * Override parent infolist to inject a structured metadata section.
     *
     * Renders ticket metadata (actor, entities, reason, context) based on
     * ticket_type. Falls back to a generic key-value grid for unknown types.
     */
    public function infolist(Schema $schema): Schema
    {
        $parent = parent::infolist($schema);

        /** @var Ticket $ticket */
        $ticket = $this->getRecord();
        $metadata = $ticket->metadata ?? [];

        // Load the subjects collection once and thread it through both section
        // builders, so the metadata sections can check $subjects->contains(...)
        // for de-duplication instead of re-querying (2 queries/view avoided).
        /** @var Collection<int, TicketSubjectLink> $subjects */
        $subjects = $ticket->subjects()->with('subject')->get();

        if (empty($metadata) && $subjects->isEmpty()) {
            return $parent;
        }

        $subjectsSection = $this->buildSubjectsSection($subjects);
        $metadataSection = $this->buildMetadataSection($ticket, $metadata, $subjects);

        if ($metadataSection === null && $subjectsSection === null) {
            return $parent;
        }

        // Insert the sections into the left column (first Group, columnSpan 2)
        // after the Ticket Information section. Subjects render first (the
        // entities the ticket is *about*), then the legacy metadata payload.
        $components = $parent->getComponents();
        if (isset($components[0]) && $components[0] instanceof Group) {
            $leftChildren = $components[0]->getChildComponents();
            $toInsert = array_values(array_filter([$subjectsSection, $metadataSection]));
            array_splice($leftChildren, 1, 0, $toInsert);
            $components[0]->childComponents($leftChildren);
        }

        return $parent;
    }

    /**
     * Build the ticket-subjects Infolist section: the host-app entities this
     * ticket is *about* (Game, User, Campaign, Location, GameSystem, Review),
     * each rendered as a chip with its model-owned deep link. Renders nothing
     * when the ticket has no subjects (legacy metadata tickets fall back to
     * buildContentReportSection/buildReviewReportSection).
     *
     * @param  Collection<int, TicketSubjectLink>  $subjects  Pre-loaded with the 'subject' relation.
     */
    protected function buildSubjectsSection(Collection $subjects): ?Section
    {
        if ($subjects->isEmpty()) {
            return null;
        }

        $entries = [];
        foreach ($subjects as $link) {
            // Entry name must be a stable, backslash-free token unique within
            // the ticket. subject_type is the morph alias (game/campaign/...)
            // for aliased models or the FQCN otherwise; slug() normalizes both
            // so Filament never sees a key like "subject_App\Models\User_<id>".
            $entryName = 'subject_'.Str::slug($link->subject_type).'_'.$link->subject_id;
            $entryLabel = $link->role ? ucfirst($link->role) : __('Subject');

            $subject = $link->subject;

            if (! $subject instanceof TicketSubject) {
                // Subject model was deleted or no longer implements the contract;
                // render a minimal chip from the link row so the audit trail stays visible.
                $entries[] = Infolists\Components\TextEntry::make($entryName)
                    ->label($entryLabel)
                    ->state($link->subject_type.' #'.$link->subject_id)
                    ->color('gray');

                continue;
            }

            // Label carries the role (e.g. "Reported"); the state is just the
            // entity title — avoids rendering the role twice in one chip.
            $entries[] = Infolists\Components\TextEntry::make($entryName)
                ->label($entryLabel)
                ->state($subject->ticketSubjectTitle())
                ->url($subject->ticketSubjectUrl(), shouldOpenInNewTab: true)
                ->color('primary');
        }

        return Section::make(__('Linked entities'))
            ->description(__('Host-app records this ticket is about'))
            ->schema($entries)
            ->collapsible();
    }

    /**
     * Build the metadata Infolist section for a ticket.
     *
     * @param  array<string, mixed>  $metadata
     * @param  Collection<int, TicketSubjectLink>  $subjects  Pre-loaded for de-dup checks.
     */
    protected function buildMetadataSection(Ticket $ticket, array $metadata, Collection $subjects): ?Section
    {
        $ticketType = $ticket->ticket_type;

        // Decide which schema to render
        return match ($ticketType) {
            'content_report' => $this->buildContentReportSection($metadata, $subjects),
            'review_report' => $this->buildReviewReportSection($metadata, $subjects),
            'game_system_request' => $this->buildGameSystemRequestSection($metadata),
            'venue_proposal' => $this->buildVenueProposalSection($metadata),
            'venue_claim' => $this->buildVenueClaimSection($metadata),
            'account_recovery', 'data_export_request' => $this->buildAccountSupportSection($metadata),
            'billing_support' => $this->buildBillingSupportSection($metadata),
            default => $this->buildGenericMetadataSection($metadata),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  Collection<int, TicketSubjectLink>  $subjects
     */
    protected function buildContentReportSection(array $metadata, Collection $subjects): Section
    {
        $entries = [];

        // When the ticket has first-class subjects (post-TicketSubjects migration),
        // the reported entity is rendered by buildSubjectsSection(); skip the
        // metadata-derived entity chip here to avoid duplication. Legacy tickets
        // without subjects keep rendering it from metadata. Checked against the
        // pre-loaded collection (no extra query).
        $hasReportedSubject = $subjects->contains(fn ($s) => $s->role === 'reported');

        // Reported entity
        $entityType = isset($metadata['entity_type']) ? self::asString($metadata['entity_type']) : null;
        $entityId = isset($metadata['entity_id']) ? self::asString($metadata['entity_id']) : null;
        $entityName = isset($metadata['entity_name']) ? self::asString($metadata['entity_name']) : $entityId;

        if ($entityType && ! $hasReportedSubject) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_entity_type')
                ->label('Entity type')
                ->state(ucfirst($entityType))
                ->badge()
                ->color('info');
        }

        if ($entityName && $entityId && ! $hasReportedSubject) {
            $url = $this->resolveEntityUrl($entityType, $entityId);
            $entries[] = Infolists\Components\TextEntry::make('metadata_entity')
                ->label('Reported entity')
                ->state($entityName)
                ->url($url, shouldOpenInNewTab: true)
                ->color('primary');
        }

        if ($entityId && ! $hasReportedSubject) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_entity_id')
                ->label('Entity ID')
                ->state($entityId)
                ->copyable()
                ->color('gray')
                ->limit(30)
                ->tooltip($entityId);
        }

        // Cover image preview: when the reported entity carries a host-uploaded
        // cover (rung 1 of resolveCoverUrl()), surface it inline so the reviewer
        // can see the offending image without leaving the ticket. Entities on the
        // representative/default rung render nothing here (nothing image-specific
        // to review). Reactive moderation: the cover is shown so the reviewer can
        // pick the proportionate Clear Cover action vs full Remove Content.
        // buildContentReportSection() does not receive $ticket, so resolve
        // the carrying entity from the metadata-derived type/id in scope.
        $coverEntity = match ($entityType) {
            'game' => ($entityId !== null && $entityId !== '') ? Game::find($entityId) : null,
            'campaign' => ($entityId !== null && $entityId !== '') ? Campaign::find($entityId) : null,
            default => null,
        };
        if ($coverEntity !== null && $coverEntity->hasCover()) {
            $coverUrl = $coverEntity->resolveCoverUrl();
            if (is_string($coverUrl) && $coverUrl !== '') {
                // Infolists Placeholder renders arbitrary HtmlString via
                // ->content(); TextEntry has no content() method (PHPStan L9).
                $entries[] = Placeholder::make('metadata_cover_preview')
                    ->label('Cover image (reported)')
                    ->content(new HtmlString(
                        '<div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">'
                        .'<img src="'.e($coverUrl).'" alt="Reported cover image" class="w-full max-h-64 object-contain bg-gray-50 dark:bg-gray-800">'
                        .'</div>'
                        .'<p class="mt-1 text-xs text-gray-500">Host-uploaded cover. Use \"Clear Cover Image\" to remove only this image.</p>'
                    ));
            }
        }

        // Report reason
        if (isset($metadata['report_reason'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_reason')
                ->label('Reason')
                ->state(ucfirst(self::asString($metadata['report_reason'])))
                ->badge()
                ->color('warning');
        }

        // Description / additional details
        if (! empty($metadata['description'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_details')
                ->label('Additional details')
                ->state($metadata['description'])
                ->columnSpanFull();
        }

        // Structured schema fields
        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Report Details')
            ->schema($entries)
            ->columns(2)
            ->icon('heroicon-o-shield-exclamation');
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  Collection<int, TicketSubjectLink>  $subjects
     */
    protected function buildReviewReportSection(array $metadata, Collection $subjects): Section
    {
        $entries = [];

        // When the ticket has first-class subjects (post-TicketSubjects
        // migration), the review + author render via buildSubjectsSection().
        // Skip the metadata-derived review_id / review_author chips here to
        // avoid duplication. Checked against the pre-loaded collection.
        $hasSubjects = $subjects->isNotEmpty();

        // Review info
        if (isset($metadata['review_id']) && ! $hasSubjects) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_review_id')
                ->label('Review ID')
                ->state($metadata['review_id'])
                ->copyable();
        }

        // Review author
        $reviewAuthorId = $metadata['review_author_id'] ?? null;
        if ($reviewAuthorId && ! $hasSubjects) {
            $author = User::find(self::asString($reviewAuthorId));
            $entries[] = Infolists\Components\TextEntry::make('metadata_review_author')
                ->label('Review author')
                ->state($author->name ?? self::asString($reviewAuthorId))
                ->url($author ? "/profile/{$author->id}" : null, shouldOpenInNewTab: true)
                ->color('primary');
        }

        if (isset($metadata['report_reason'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_reason')
                ->label('Reason')
                ->state(ucfirst(self::asString($metadata['report_reason'])))
                ->badge()
                ->color('warning');
        }

        if (! empty($metadata['description'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_details')
                ->label('Additional details')
                ->state($metadata['description'])
                ->columnSpanFull();
        }

        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Review Report Details')
            ->schema($entries)
            ->columns(2)
            ->icon('heroicon-o-shield-exclamation');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function buildGameSystemRequestSection(array $metadata): Section
    {
        $entries = [];

        // Game system type
        if (isset($metadata['game_system_type'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_system_type')
                ->label('System type')
                ->state(ucfirst(str_replace('_', ' ', self::asString($metadata['game_system_type']))))
                ->badge()
                ->color('info');
        }

        // BGG URL
        if (! empty($metadata['bgg_url'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_bgg_url')
                ->label('BGG URL')
                ->state($metadata['bgg_url'])
                ->url(self::asString($metadata['bgg_url']), shouldOpenInNewTab: true)
                ->color('primary')
                ->columnSpanFull();
        } else {
            $entries[] = Infolists\Components\TextEntry::make('metadata_bgg_url')
                ->label('BGG URL')
                ->state('Not provided')
                ->color('gray')
                ->columnSpanFull();
        }

        // Publisher
        if (! empty($metadata['publisher'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_publisher')
                ->label('Publisher')
                ->state($metadata['publisher']);
        }

        // Designer
        if (! empty($metadata['designer'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_designer')
                ->label('Designer')
                ->state($metadata['designer']);
        }

        // Notes (from description in metadata or ticket description)
        if (! empty($metadata['notes']) || ! empty($metadata['description'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_notes')
                ->label('Notes')
                ->state($metadata['notes'] ?? $metadata['description'])
                ->columnSpanFull();
        }

        // Linked game system (after sync)
        if (! empty($metadata['game_system_id'])) {
            $gameSystemId = self::asString($metadata['game_system_id']);
            $gs = GameSystem::find($gameSystemId);
            $entries[] = Infolists\Components\TextEntry::make('metadata_game_system')
                ->label('Linked game system')
                ->state($gs ? $gs->name : "ID: {$gameSystemId}")
                ->url($gs ? GameSystemResource::getUrl('edit', ['record' => $gs->id]) : null, shouldOpenInNewTab: true)
                ->color('success')
                ->icon('heroicon-o-check-circle');
        }

        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Game System Request Details')
            ->schema($entries)
            ->columns(2);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function buildAccountSupportSection(array $metadata): Section
    {
        $entries = [];

        if (isset($metadata['issue_type'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_issue_type')
                ->label('Issue type')
                ->state(ucfirst(str_replace('_', ' ', self::asString($metadata['issue_type']))))
                ->badge()
                ->color('info');
        }

        if (! empty($metadata['details'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_details')
                ->label('Details')
                ->state($metadata['details'])
                ->columnSpanFull();
        }

        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Support Details')
            ->schema($entries)
            ->columns(2);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function buildBillingSupportSection(array $metadata): Section
    {
        $entries = [];

        if (isset($metadata['issue_type'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_issue_type')
                ->label('Issue type')
                ->state(ucfirst(str_replace('_', ' ', self::asString($metadata['issue_type']))))
                ->badge()
                ->color('info');
        }

        // Subscription context
        if (isset($metadata['has_subscription'])) {
            $entries[] = Infolists\Components\IconEntry::make('metadata_has_subscription')
                ->label('Has subscription')
                ->state($metadata['has_subscription'])
                ->boolean();
        }

        if (! empty($metadata['subscription_status'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_subscription_status')
                ->label('Subscription status')
                ->state($metadata['subscription_status'])
                ->badge();
        }

        if (! empty($metadata['paddle_subscription_id'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_paddle_sub_id')
                ->label('Paddle subscription ID')
                ->state($metadata['paddle_subscription_id'])
                ->copyable()
                ->color('gray');
        }

        if (! empty($metadata['details'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_details')
                ->label('Details')
                ->state($metadata['details'])
                ->columnSpanFull();
        }

        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Billing Details')
            ->schema($entries)
            ->columns(2);
    }

    /**
     * Generic fallback: render all metadata keys as a key-value grid.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function buildGenericMetadataSection(array $metadata): Section
    {
        // Skip internal keys
        $skip = ['schema', 'actor', 'action', 'entities', 'reported_user', 'context', 'game_system_request'];
        $entries = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, $skip) || is_array($value)) {
                continue;
            }

            $label = ucfirst(str_replace('_', ' ', $key));
            $entries[] = Infolists\Components\TextEntry::make("metadata_{$key}")
                ->label($label)
                ->state($value)
                ->copyable();
        }

        if (empty($entries)) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_raw')
                ->label('Raw metadata')
                ->state(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->columnSpanFull();
        }

        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Metadata')
            ->schema($entries)
            ->columns(2)
            ->collapsible();
    }

    /**
     * Build entries from the structured payload schema (actor, entities, reason).
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, Infolists\Components\TextEntry>
     */
    protected function buildStructuredEntries(array $metadata): array
    {
        $entries = [];

        // Actor
        $actor = $metadata['actor'] ?? null;
        if (is_array($actor)) {
            $actorName = isset($actor['name']) ? self::asString($actor['name']) : 'Unknown';
            $actorUrl = ($actor['type'] ?? '') === 'user' && isset($actor['id'])
                ? '/profile/'.self::asString($actor['id'])
                : null;

            $entries[] = Infolists\Components\TextEntry::make('structured_actor')
                ->label('Actor')
                ->state($actorName)
                ->url($actorUrl, shouldOpenInNewTab: true)
                ->color('primary');
        }

        // Entities
        $entities = $metadata['entities'] ?? null;
        if (is_array($entities)) {
            foreach ($entities as $i => $entity) {
                if (! is_array($entity)) {
                    continue;
                }
                $name = $entity['name'] ?? $entity['id'] ?? 'Unknown';
                $url = $this->resolveEntityUrl(
                    isset($entity['type']) ? self::asString($entity['type']) : null,
                    isset($entity['id']) ? self::asString($entity['id']) : null,
                );
                $entries[] = Infolists\Components\TextEntry::make("structured_entity_{$i}")
                    ->label('Entity'.(count($entities) > 1 ? ' '.($i + 1) : ''))
                    ->state($name)
                    ->url($url, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->copyable();
            }
        }

        return $entries;
    }

    /**
     * Safely convert a mixed metadata value to a string.
     *
     * Scalar values are stringified; arrays, objects, and other non-scalar
     * values become an empty string. This avoids PHPStan's level-9 rejection
     * of casting `mixed` directly to string.
     */
    protected static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Resolve a URL for an entity type + ID.
     */
    protected function resolveEntityUrl(?string $type, ?string $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return match ($type) {
            'user' => "/profile/{$id}",
            'game' => "/dashboard/games/{$id}",
            'campaign' => "/dashboard/campaigns/{$id}",
            'location' => "/admin/locations/{$id}/edit",
            'review' => null, // Reviews don't have a direct admin URL
            default => null,
        };
    }

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();

        // Insert venue proposal actions for Events department venue_proposal tickets
        $venueProposalActions = $this->getVenueProposalActions();
        if (! empty($venueProposalActions)) {
            array_splice($actions, 0, 0, $venueProposalActions);
        }

        // Insert venue claim actions for Events department venue_claim tickets
        $venueClaimActions = $this->getVenueClaimActions();
        if (! empty($venueClaimActions)) {
            array_splice($actions, 0, 0, $venueClaimActions);
        }

        // Insert data export action for data_export_request tickets
        $dataExportActions = $this->getDataExportActions();
        if (! empty($dataExportActions)) {
            array_splice($actions, 0, 0, $dataExportActions);
        }

        // Insert content moderation actions for Safety department content_report tickets
        $contentReportActions = $this->getContentReportActions();
        if (! empty($contentReportActions)) {
            array_splice($actions, 0, 0, $contentReportActions);
        }

        // Insert review moderation actions for Safety department review_report tickets
        $reviewActions = $this->getReviewModerationActions();
        if (! empty($reviewActions)) {
            array_splice($actions, 0, 0, $reviewActions);
        }

        // Insert game-system-specific actions before the generic resolve/close actions
        $gameSystemActions = $this->getGameSystemActions();

        if (! empty($gameSystemActions)) {
            // Prepend game system actions at the beginning
            array_splice($actions, 0, 0, $gameSystemActions);
        }

        return $actions;
    }

    /**
     * Get game-system-specific actions. Only visible on game system request tickets.
     *
     * @return array<int, Action>
     */
    protected function getGameSystemActions(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        if (! $this->isGameSystemRequest($ticket)) {
            return [];
        }

        return [
            Action::make('syncFromBgg')
                ->label('Sync from BGG')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sync Game System from BGG')
                ->modalDescription(function () use ($ticket) {
                    $bggUrl = $ticket->metadata['bgg_url'] ?? null;

                    return $bggUrl
                        ? 'This will sync game data from BGG using the URL: '.self::asString($bggUrl)
                        : 'No BGG URL found in ticket metadata.';
                })
                ->modalSubmitActionLabel('Sync Now')
                ->action(function () use ($ticket) {
                    $this->performBggSync($ticket);
                })
                ->visible(function () use ($ticket) {
                    return ! empty($ticket->metadata['bgg_url'] ?? null);
                }),

            Action::make('searchBgg')
                ->label('Search BGG')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->modalHeading('Search BoardGameGeek')
                ->modalDescription('Search for the requested game on BGG to link BGG data.')
                ->modalSubmitActionLabel('Search')
                ->modalWidth('4xl')
                ->schema([
                    TextInput::make('bgg_search_query')
                        ->label('Search Query')
                        ->placeholder('e.g. Ticket to Ride, Catan, Gloomhaven')
                        ->required()
                        ->maxLength(255)
                        ->default(function () use ($ticket) {
                            $service = app(GameSystemRequestService::class);

                            return $service->extractName($ticket);
                        }),
                    Placeholder::make('bgg_search_results_display')
                        ->label('Search Results')
                        ->hidden(fn () => empty($this->bggSearchResults))
                        ->content(fn () => $this->renderSearchResultsTable()),
                    Placeholder::make('bgg_selected_display')
                        ->label('Selected BGG Game')
                        ->hidden(fn () => $this->selectedBggId === null)
                        ->content(fn () => new HtmlString(
                            '<div class="fi-section rounded-xl bg-primary-50 p-3 dark:bg-primary-900/20">'
                            .'<span class="font-medium text-primary-700 dark:text-primary-300">'.e($this->selectedBggName).'</span>'
                            .' <span class="text-gray-500">(BGG ID: '.$this->selectedBggId.')</span>'
                            .'</div>'
                        )),
                    Placeholder::make('bgg_no_results_display')
                        ->label('No results found')
                        ->hidden(fn () => ! empty($this->bggSearchResults) || $this->selectedBggId !== null)
                        ->content(new HtmlString('<p class="text-gray-500">Enter a query and click Search.</p>')),
                ])
                ->modalSubmitActionLabel('Search')
                ->modalFooterActions(fn (Action $action) => [
                    Action::make('bggSearch')
                        ->label('Search')
                        ->icon(Heroicon::OutlinedMagnifyingGlass)
                        ->action(function (Get $get) {
                            $query = self::asString($get('bgg_search_query') ?? '');
                            if (! empty(trim($query))) {
                                $this->performBggSearch($query);
                            }
                        })
                        ->close(false),
                    Action::make('syncSelectedBgg')
                        ->label('Sync Selected')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('success')
                        ->visible(fn () => $this->selectedBggId !== null)
                        ->action(function () {
                            if ($this->selectedBggId === null) {
                                return;
                            }
                            $this->performBggSyncById($this->selectedBggId);
                        }),
                    Action::make('clearBggSelection')
                        ->label('Clear Selection')
                        ->color('gray')
                        ->visible(fn () => $this->selectedBggId !== null)
                        ->close(false)
                        ->action(function () {
                            $this->selectedBggId = null;
                            $this->selectedBggName = null;
                            $this->bggPreviewData = null;
                        }),
                    $action->getModalCancelAction(),
                ]),

            Action::make('createManual')
                ->label('Create Manually')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Create Game System Manually')
                ->modalDescription('Create a GameSystem from the request data without BGG sync.')
                ->modalSubmitActionLabel('Create')
                ->action(function () use ($ticket) {
                    $this->performManualCreate($ticket);
                })
                ->visible(function () use ($ticket) {
                    return empty($ticket->metadata['game_system_id'] ?? null);
                }),
        ];
    }

    /**
     * Get data export actions. Only visible on data_export_request tickets that are still open.
     *
     * @return array<int, Action>
     */
    protected function getDataExportActions(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

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
                ->action(function () use ($ticket) {
                    $this->performGenerateDataExport($ticket);
                }),
        ];
    }

    /**
     * Select a BGG search result by index.
     * Called from the rendered results table via Livewire.dispatch.
     */
    #[On('selectBggResult')]
    public function selectBggResult(int $index): void
    {
        if (! isset($this->bggSearchResults[$index])) {
            return;
        }

        $result = $this->bggSearchResults[$index];
        $this->selectedBggId = $result['bgg_id'];
        $this->selectedBggName = $result['name'];

        // Fetch full thing data for preview
        $this->fetchBggPreview($result['bgg_id']);

        Notification::make()
            ->success()
            ->title('BGG game selected')
            ->body("Selected: {$this->selectedBggName} (ID: {$this->selectedBggId})")
            ->send();
    }

    /**
     * Get review moderation actions. Only visible on Safety department review_report tickets
     * that are still open (not closed/resolved).
     *
     * @return array<int, Action>
     */
    protected function getReviewModerationActions(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        if (! $this->isReviewReport($ticket)) {
            return [];
        }

        return [
            Action::make('dismissReport')
                ->label('Dismiss Report')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Dismiss Review Report')
                ->modalDescription('This will close the ticket and keep the review published. The review will remain visible.')
                ->modalSubmitActionLabel('Dismiss')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performDismissReport($ticket);
                }),

            Action::make('removeReview')
                ->label('Remove Review')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove Review')
                ->modalDescription('This will close the ticket AND hide the review. The review will no longer be publicly visible.')
                ->modalSubmitActionLabel('Remove')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performRemoveReview($ticket);
                }),

            Action::make('escalateReport')
                ->label('Escalate')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Escalate Review Report')
                ->modalDescription('This will reassign the ticket to a Platform Admin and increase priority to Urgent.')
                ->modalSubmitActionLabel('Escalate')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performEscalateReport($ticket);
                }),
        ];
    }

    /**
     * Check if the ticket is a review report in the Safety department.
     */
    protected function isReviewReport(Ticket $ticket): bool
    {
        return ($ticket->ticket_type ?? null) === 'review_report'
            && ($ticket->department->name ?? null) === 'Safety';
    }

    /**
     * Check if the ticket is a content report in the Safety department.
     */
    protected function isContentReport(Ticket $ticket): bool
    {
        return ($ticket->ticket_type ?? null) === 'content_report'
            && ($ticket->department->name ?? null) === 'Safety';
    }

    /**
     * Check if the ticket is a venue proposal in the Events department.
     */
    protected function isVenueProposal(Ticket $ticket): bool
    {
        return app(VenueProposalService::class)->isVenueProposalTicket($ticket);
    }

    /**
     * Check if the ticket is a venue claim in the Events department.
     */
    protected function isVenueClaim(Ticket $ticket): bool
    {
        return app(VenueClaimService::class)->isVenueClaimTicket($ticket);
    }

    /**
     * Get venue proposal actions. Only visible on Events department venue_proposal tickets
     * that are still open (not closed/resolved).
     *
     * @return array<int, Action>
     */
    protected function getVenueProposalActions(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        if (! $this->isVenueProposal($ticket)) {
            return [];
        }

        return [
            Action::make('approveVenueProposal')
                ->label('Approve Venue')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve Venue Proposal')
                ->modalDescription(function () use ($ticket) {
                    $name = isset($ticket->metadata['venue_name']) ? self::asString($ticket->metadata['venue_name']) : ($ticket->subject ?? '');
                    $existing = $ticket->metadata['existing_location_id'] ?? null;
                    if ($existing) {
                        return 'This will update the existing location (ID: '.self::asString($existing).') with the proposed venue details and mark it as verified.';
                    }

                    return 'This will create a new verified location for "'.$name.'" and resolve the ticket.';
                })
                ->modalSubmitActionLabel('Approve')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performApproveVenueProposal($ticket);
                }),

            Action::make('rejectVenueProposal')
                ->label('Reject Venue')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->modalHeading('Reject Venue Proposal')
                ->modalDescription('Please provide a reason for rejecting this venue proposal.')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Rejection reason')
                        ->placeholder('e.g. Venue does not meet community guidelines, duplicate entry, etc.')
                        ->required()
                        ->maxLength(1000),
                ])
                ->modalSubmitActionLabel('Reject')
                ->visible(fn () => $ticket->isOpen())
                ->action(function (array $data) use ($ticket) {
                    $this->performRejectVenueProposal($ticket, $data['rejection_reason']);
                }),
        ];
    }

    /**
     * Get venue claim actions. Only visible on Events department venue_claim tickets
     * that are still open (not closed/resolved).
     *
     * @return array<int, Action>
     */
    protected function getVenueClaimActions(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        if (! $this->isVenueClaim($ticket)) {
            return [];
        }

        return [
            Action::make('approveVenueClaim')
                ->label('Approve Claim')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve Venue Claim')
                ->modalDescription(function () use ($ticket) {
                    $name = isset($ticket->metadata['location_name'])
                        ? self::asString($ticket->metadata['location_name'])
                        : ($ticket->subject ?? '');
                    $actor = $ticket->metadata['actor'] ?? null;
                    $claimant = is_array($actor) && isset($actor['name'])
                        ? self::asString($actor['name'])
                        : 'the claimant';

                    return 'This will assign management of "'.$name.'" to '.$claimant.' and resolve the ticket. The location address is not changed.';
                })
                ->modalSubmitActionLabel('Approve')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performApproveVenueClaim($ticket);
                }),

            Action::make('rejectVenueClaim')
                ->label('Reject Claim')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->modalHeading('Reject Venue Claim')
                ->modalDescription('Please provide a reason for rejecting this venue claim.')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Rejection reason')
                        ->placeholder('e.g. Cannot verify the claimant\'s association with this venue, duplicate claim, etc.')
                        ->required()
                        ->maxLength(1000),
                ])
                ->modalSubmitActionLabel('Reject')
                ->visible(fn () => $ticket->isOpen())
                ->action(function (array $data) use ($ticket) {
                    $this->performRejectVenueClaim($ticket, $data['rejection_reason']);
                }),
        ];
    }

    /**
     * Build the metadata Infolist section for a venue proposal ticket.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function buildVenueProposalSection(array $metadata): Section
    {
        $entries = [];

        // Venue name
        if (! empty($metadata['venue_name'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_venue_name')
                ->label('Venue name')
                ->state($metadata['venue_name']);
        }

        // Address
        if (! empty($metadata['venue_address'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_venue_address')
                ->label('Address')
                ->state($metadata['venue_address']);
        }

        // City
        if (! empty($metadata['venue_city'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_venue_city')
                ->label('City')
                ->state($metadata['venue_city']);
        }

        // Postal code
        if (! empty($metadata['venue_postal_code'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_venue_postal_code')
                ->label('Postal code')
                ->state($metadata['venue_postal_code']);
        }

        // Country
        if (! empty($metadata['venue_country'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_venue_country')
                ->label('Country')
                ->state($metadata['venue_country']);
        }

        // Venue type
        if (! empty($metadata['venue_type'])) {
            $venueTypeLabel = VenueType::tryFrom(self::asString($metadata['venue_type']))?->label() ?? $metadata['venue_type'];
            $entries[] = Infolists\Components\TextEntry::make('metadata_venue_type')
                ->label('Venue type')
                ->state($venueTypeLabel)
                ->badge()
                ->color('info');
        }

        // Website
        if (! empty($metadata['website_url'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_website_url')
                ->label('Website')
                ->state($metadata['website_url'])
                ->url(self::asString($metadata['website_url']), shouldOpenInNewTab: true)
                ->color('primary')
                ->columnSpanFull();
        }

        // Geocoded display name
        if (! empty($metadata['geocoded_display_name'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_geocoded_display')
                ->label('Geocoded as')
                ->state($metadata['geocoded_display_name'])
                ->columnSpanFull()
                ->color('gray');
        }

        // Existing location link
        if (! empty($metadata['existing_location_id'])) {
            $existingLocation = Location::find(self::asString($metadata['existing_location_id']));
            $entries[] = Infolists\Components\TextEntry::make('metadata_existing_location')
                ->label('Existing location')
                ->state($existingLocation ? $existingLocation->name : 'ID: '.self::asString($metadata['existing_location_id']))
                ->url($existingLocation ? "/admin/locations/{$existingLocation->id}/edit" : null, shouldOpenInNewTab: true)
                ->color('warning')
                ->icon('heroicon-o-link');
        }

        // Linked location (after approval)
        if (! empty($metadata['location_id'])) {
            $location = Location::find(self::asString($metadata['location_id']));
            $entries[] = Infolists\Components\TextEntry::make('metadata_linked_location')
                ->label('Linked location')
                ->state($location ? $location->name : 'ID: '.self::asString($metadata['location_id']))
                ->url($location ? "/admin/locations/{$location->id}/edit" : null, shouldOpenInNewTab: true)
                ->color('success')
                ->icon('heroicon-o-check-circle');
        }

        // Proposer notes
        $notes = $metadata['proposer_notes'] ?? $metadata['notes'] ?? null;
        if (! empty($notes)) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_notes')
                ->label('Notes')
                ->state($notes)
                ->columnSpanFull();
        }

        // Structured schema fields (actor)
        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Venue Proposal Details')
            ->schema($entries)
            ->columns(2)
            ->icon('heroicon-o-map-pin');
    }

    /**
     * Build the metadata Infolist section for a venue claim ticket.
     *
     * Shows the venue name + city (name/city only — MEM717), the claimant
     * (via the structured actor entry), the claimant's justification, optional
     * proof (website), and a linked-location entry that flips to "now managed"
     * once the claim is approved.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function buildVenueClaimSection(array $metadata): Section
    {
        $entries = [];

        // Venue name (identity — name + city only, MEM717)
        if (! empty($metadata['location_name'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_claim_venue_name')
                ->label('Venue')
                ->state($metadata['location_name']);
        }

        // City
        if (! empty($metadata['location_city'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_claim_venue_city')
                ->label('City')
                ->state($metadata['location_city']);
        }

        // Justification (claimant notes)
        $notes = $metadata['claimant_notes'] ?? $metadata['details'] ?? null;
        if (! empty($notes)) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_claim_notes')
                ->label('Justification')
                ->state($notes)
                ->columnSpanFull();
        }

        // Optional proof / website
        if (! empty($metadata['website_url'])) {
            $entries[] = Infolists\Components\TextEntry::make('metadata_claim_website')
                ->label('Proof / website')
                ->state($metadata['website_url'])
                ->url(self::asString($metadata['website_url']), shouldOpenInNewTab: true)
                ->color('primary')
                ->columnSpanFull();
        }

        // Claimed venue link. Once approved the Location's managed_by is set.
        if (! empty($metadata['location_id'])) {
            $location = Location::find(self::asString($metadata['location_id']));
            $managedBy = $location?->managed_by;
            $entries[] = Infolists\Components\TextEntry::make('metadata_claim_linked_location')
                ->label($managedBy !== null ? 'Claimed venue (now managed)' : 'Claimed venue')
                ->state($location ? $location->name : 'ID: '.self::asString($metadata['location_id']))
                ->url($location ? "/admin/locations/{$location->id}/edit" : null, shouldOpenInNewTab: true)
                ->color($managedBy !== null ? 'success' : 'warning')
                ->icon($managedBy !== null ? 'heroicon-o-check-circle' : 'heroicon-o-link');
        }

        // Structured schema fields (actor → claimant)
        if (isset($metadata['schema'])) {
            $entries = array_merge($entries, $this->buildStructuredEntries($metadata));
        }

        return Section::make('Venue Claim Details')
            ->schema($entries)
            ->columns(2)
            ->icon('heroicon-o-map-pin');
    }

    /**
     * Approve a venue proposal: create/update Location with is_verified=true, resolve ticket.
     */
    protected function performApproveVenueProposal(Ticket $ticket): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);
            $proposalService = app(VenueProposalService::class);

            $location = DB::transaction(function () use ($ticket, $admin, $ticketService, $proposalService) {
                $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                if (! $lockedTicket->isOpen()) {
                    throw new \RuntimeException('This ticket is no longer open.');
                }

                // Use VenueProposalService to create/update the location
                $location = $proposalService->approveProposal($lockedTicket);

                // Add internal note linking to the created/updated location
                $ticketService->addNote($lockedTicket, $admin, "Venue proposal approved. Location: {$location->name} (ID: {$location->id})");

                // Resolve the ticket
                $ticketService->resolve($lockedTicket, $admin);

                return $location;
            });

            Log::info('venue_proposal.approved_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'location_id' => $location->id,
                'location_name' => $location->name,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title('Venue approved')
                ->body("The venue \"{$location->name}\" has been created/updated as a verified location.")
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to approve venue proposal', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Approval failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Reject a venue proposal: resolve ticket with rejection reason, no location changes.
     */
    protected function performRejectVenueProposal(Ticket $ticket, string $reason): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            DB::transaction(function () use ($ticket, $admin, $ticketService, $reason) {
                $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                if (! $lockedTicket->isOpen()) {
                    throw new \RuntimeException('This ticket is no longer open.');
                }

                // Add reply with rejection reason
                $ticketService->reply($lockedTicket, $admin, "Venue proposal rejected: {$reason}");

                // Add internal note
                $ticketService->addNote($lockedTicket, $admin, "Venue proposal rejected. Reason: {$reason}");

                // Resolve the ticket
                $ticketService->resolve($lockedTicket, $admin);
            });

            Log::info('venue_proposal.rejected_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'reason' => $reason,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Venue proposal rejected')
                ->body('The ticket has been resolved with the rejection reason.')
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to reject venue proposal', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Rejection failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Approve a venue claim: assign management of the existing Location to the
     * claimant and resolve the ticket.
     *
     * VenueClaimService::approveClaim owns the pessimistic-lock transaction,
     * the open-ticket guard, the managed_by mutation, and the TicketService
     * note + resolve (see T02). This caller stays thin on purpose: re-doing
     * the lock, note, or resolve here would double-execute (e.g. resolving an
     * already-resolved ticket).
     */
    protected function performApproveVenueClaim(Ticket $ticket): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }

            $claimService = app(VenueClaimService::class);

            // approveClaim owns lockForUpdate + isOpen guard + managed_by + note + resolve.
            $location = $claimService->approveClaim($ticket);

            Log::info('venue_claim.approved_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'location_id' => $location->id,
                'location_name' => $location->name,
                'claimant_id' => $location->managed_by,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title('Venue claim approved')
                ->body("The venue \"{$location->name}\" is now managed by the claimant.")
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to approve venue claim', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Approval failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Reject a venue claim: resolve ticket with rejection reason, no Location changes.
     *
     * VenueClaimService::rejectClaim owns the reply + note + resolve (T02).
     */
    protected function performRejectVenueClaim(Ticket $ticket, string $reason): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }

            $claimService = app(VenueClaimService::class);

            // rejectClaim owns reply + note + resolve. No Location mutation.
            $claimService->rejectClaim($ticket, $reason);

            Log::info('venue_claim.rejected_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'reason' => $reason,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Venue claim rejected')
                ->body('The ticket has been resolved with the rejection reason.')
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to reject venue claim', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Rejection failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Get content moderation actions. Only visible on Safety department content_report tickets
     * that are still open (not closed/resolved).
     *
     * @return array<int, Action>
     */
    protected function getContentReportActions(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        if (! $this->isContentReport($ticket)) {
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
                ->action(function () use ($ticket) {
                    $this->performDismissContentReport($ticket);
                }),

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
                ->action(function (array $data) use ($ticket, $entityType, $entityName) {
                    $this->performWarnUser($ticket, $entityType, $entityName, $data['warning_note'] ?? null);
                }),

            Action::make('clearCover')
                ->label('Clear Cover Image')
                ->icon(Heroicon::OutlinedPhoto)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Clear Cover Image')
                ->modalDescription("This removes ONLY the host-uploaded cover image on the reported {$entityType} — the {$entityType} itself stays published and falls back to its representative/default cover. The owner is notified. Use this instead of \"Remove Content\" when only the image is the issue.")
                ->modalSubmitActionLabel('Clear Cover')
                ->visible(function () use ($ticket, $entityType) {
                    // Proportionate toakedown: only offer when the reported
                    // entity is a game/campaign that actually carries a
                    // host-uploaded cover (rung 1). Entities on the
                    // representative/default rung have nothing to clear.
                    if (! $ticket->isOpen() || ! in_array($entityType, ['game', 'campaign'], true)) {
                        return false;
                    }
                    $entity = $this->resolveReportedEntity($ticket, $entityType);

                    return $entity !== null && method_exists($entity, 'hasCover') && $entity->hasCover();
                })
                ->action(function () use ($ticket, $entityType, $entityName) {
                    $this->performClearCover($ticket, $entityType, $entityName);
                }),

            Action::make('removeContent')
                ->label('Remove Content')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove Content')
                ->modalDescription("This will close the ticket, remove/hide the reported {$entityType}, and notify the content owner.")
                ->modalSubmitActionLabel('Remove')
                ->visible(fn () => $ticket->isOpen() && $entityType !== 'user')
                ->action(function () use ($ticket, $entityType, $entityName) {
                    $this->performRemoveContent($ticket, $entityType, $entityName);
                }),

            Action::make('suspendUser')
                ->label('Suspend User')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Suspend User Account')
                ->modalDescription('This will close the ticket, suspend the user account (is_disabled = true), and notify the user.')
                ->modalSubmitActionLabel('Suspend')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket, $entityType) {
                    $this->performSuspendUser($ticket, $entityType);
                }),

            Action::make('escalateContentReport')
                ->label('Escalate')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Escalate Content Report')
                ->modalDescription('This will reassign the ticket to a Platform Admin and increase priority to Urgent.')
                ->modalSubmitActionLabel('Escalate')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performEscalateContentReport($ticket);
                }),
        ];
    }

    /**
     * Dismiss the report: close ticket, keep review published.
     */
    protected function performDismissReport(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            if (! $user instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            DB::transaction(function () use ($ticket, $user, $ticketService) {
                // Restore review to published status before closing
                $this->restoreReviewStatus($ticket, 'published');

                // Add internal note after review update succeeds
                $ticketService->addNote($ticket, $user, 'Report dismissed by admin');

                // Close the ticket
                $ticketService->close($ticket, $user);
            });

            Log::info('review.report.dismissed', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'review_id' => $ticket->metadata['review_id'] ?? null,
                'admin_id' => $user->id,
            ]);

            Notification::make()
                ->success()
                ->title('Report dismissed')
                ->body('The ticket has been closed and the review remains published.')
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to dismiss review report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Dismiss failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Remove the review: close ticket, hide review.
     */
    protected function performRemoveReview(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            if (! $user instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            DB::transaction(function () use ($ticket, $user, $ticketService) {
                // Hide the review before closing
                $this->restoreReviewStatus($ticket, 'hidden');

                // Add internal note after review update succeeds
                $ticketService->addNote($ticket, $user, 'Review removed by admin');

                // Close the ticket
                $ticketService->close($ticket, $user);
            });

            Log::info('review.report.removed', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'review_id' => $ticket->metadata['review_id'] ?? null,
                'admin_id' => $user->id,
            ]);

            Notification::make()
                ->success()
                ->title('Review removed')
                ->body('The ticket has been closed and the review has been hidden.')
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to remove review', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Remove failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Find another Platform Admin to assign escalation to.
     * Falls back to the current user if no other Platform Admin exists.
     *
     * @return array{admin: User, assigned_name: string}
     */
    protected function findPlatformAdminForEscalation(User $currentUser): array
    {
        $admin = User::role('Platform Admin')
            ->where('id', '!=', $currentUser->id)
            ->first() ?? $currentUser;

        return [
            'admin' => $admin,
            'assigned_name' => $admin->name,
        ];
    }

    protected function performEscalateReport(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            if (! $user instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            $assignmentInfo = null;
            DB::transaction(function () use ($ticket, $user, $ticketService, &$assignmentInfo) {
                // Add internal note
                $ticketService->addNote($ticket, $user, "Escalated by {$user->name}");

                // Increase priority to Urgent
                $ticketService->changePriority($ticket, TicketPriority::Urgent, $user);

                // Find a Platform Admin to assign to
                $assignmentInfo = $this->findPlatformAdminForEscalation($user);
                ['admin' => $platformAdmin] = $assignmentInfo;

                if ($platformAdmin->id !== $user->id) {
                    // Ticket::assign() is UUID-safe since escalated-laravel v1.4.0
                    // (TicketAssigned.$agentId is int|string) and fires the event,
                    // activity log, and broadcast that updateQuietly suppressed.
                    // Deferred to afterCommit(): the DispatchWebhook listener does a
                    // blocking Http::timeout(10) call, which would hold the row
                    // lock for the whole transaction and risk send-on-rollback.
                    $admin = $platformAdmin;
                    DB::afterCommit(fn () => $ticket->assign($admin, $user));
                }
            });

            if ($assignmentInfo === null) {
                return;
            }
            ['admin' => $platformAdmin, 'assigned_name' => $assignedName] = $assignmentInfo;

            Log::info('review.report.escalated', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'review_id' => $ticket->metadata['review_id'] ?? null,
                'escalated_by' => $user->id,
                'assigned_to' => $platformAdmin->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Report escalated')
                ->body("Priority set to Urgent and reassigned to {$assignedName}.")
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to escalate review report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Escalation failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Update the review status based on the ticket metadata.
     * The ReviewObserver will handle aggregate recalculation via the 'updated' hook.
     */
    protected function restoreReviewStatus(Ticket $ticket, string $status): void
    {
        $reviewId = $ticket->metadata['review_id'] ?? null;

        if (! $reviewId) {
            throw new \RuntimeException('Review ID is missing from ticket metadata.');
        }

        $review = Review::find(self::asString($reviewId));

        if (! $review) {
            throw new \RuntimeException('Review '.self::asString($reviewId).' was not found.');
        }

        $review->update(['status' => $status]);

        Log::info('review.status.updated_from_ticket', [
            'review_id' => $review->id,
            'new_status' => $status,
            'ticket_id' => $ticket->id,
        ]);
    }

    /**
     * Check if the ticket is a game system request.
     */
    protected function isGameSystemRequest(Ticket $ticket): bool
    {
        return app(GameSystemRequestService::class)->isGameSystemRequestTicket($ticket);
    }

    /**
     * Generate a user data export, reply to the ticket with a signed download URL, and resolve.
     */
    protected function performGenerateDataExport(Ticket $ticket): void
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

        $admin = auth()->user();
        if (! $admin instanceof User) {
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

    /**
     * Dismiss content report: close ticket with no action on the reported entity.
     */
    protected function performDismissContentReport(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            if (! $user instanceof User) {
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
            Log::error('Failed to dismiss content report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Dismiss failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Warn user: close ticket, send warning notification to the content owner.
     */
    protected function performWarnUser(Ticket $ticket, ?string $entityType, ?string $entityName, ?string $note): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            $reportedUser = $this->resolveReportedUser($ticket, $entityType);
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

            // Send warning notification after transaction commits
            $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
            $reportedUser->notify(new ContentReportWarning(
                $entityType ?? 'content',
                $entityName ?? 'reported content',
                self::asString($reason),
            ));

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
            Log::error('Failed to warn user for content report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Warning failed')
                ->body($e->getMessage())
                ->send();
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
     * action instead of canceling the whole entity via performRemoveContent().
     * Mirrors performRemoveContent()'s structure (transaction + note + close +
     * owner notify) but calls clearCoverImage() and scopes the notification so
     * the owner learns the cover specifically was removed.
     */
    protected function performClearCover(Ticket $ticket, ?string $entityType, ?string $entityName): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            $entity = $this->resolveReportedEntity($ticket, $entityType);
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
                $owner = $this->resolveReportedUser($ticket, $entityType);
                if ($owner) {
                    $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
                    $owner->notify(new ContentRemoved(
                        $entityType ?? 'content',
                        $entityName ?? 'reported content',
                        self::asString($reason),
                        'cover_image',
                    ));
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
            Log::error('Failed to clear cover for report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Clear cover failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Remove content: close ticket, hide/remove the reported entity, notify owner.
     */
    protected function performRemoveContent(Ticket $ticket, ?string $entityType, ?string $entityName): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            $entityId = $ticket->metadata['entity_id'] ?? null;
            $removed = false;

            // Remove content inside transaction so ticket close + content removal
            // are atomic. If either fails, everything rolls back.
            DB::transaction(function () use ($ticket, $admin, $ticketService, $entityType, $entityId, &$removed) {
                match ($entityType) {
                    'game' => $removed = $this->removeGame($entityId !== null ? self::asString($entityId) : null),
                    'campaign' => $removed = $this->removeCampaign($entityId !== null ? self::asString($entityId) : null),
                    default => $removed = false,
                };

                $ticketService->addNote($ticket, $admin, $removed
                    ? ucfirst($entityType ?? 'content').' removed by admin.'
                    : 'Removal attempted but entity not found or already removed.');
                $ticketService->close($ticket, $admin);
            });

            // Notify the content owner
            if ($removed) {
                $reportedUser = $this->resolveReportedUser($ticket, $entityType);
                if ($reportedUser) {
                    $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
                    $reportedUser->notify(new ContentRemoved(
                        $entityType ?? 'content',
                        $entityName ?? 'reported content',
                        self::asString($reason),
                    ));
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
            Log::error('Failed to remove content for report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Remove failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Suspend user: close ticket, disable the reported user account, notify user.
     */
    protected function performSuspendUser(Ticket $ticket, ?string $entityType): void
    {
        try {
            $admin = auth()->user();
            if (! $admin instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            $reportedUser = $this->resolveReportedUser($ticket, $entityType);
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

            // Send suspension notification after transaction commits
            $reason = $ticket->metadata['report_reason'] ?? 'community guidelines violation';
            $reportedUser->notify(new AccountSuspended(self::asString($reason)));

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
            Log::error('Failed to suspend user for content report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Suspension failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Escalate content report: reassign to Platform Admin, increase priority to Urgent.
     */
    protected function performEscalateContentReport(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            if (! $user instanceof User) {
                return;
            }
            $ticketService = app(TicketService::class);

            $assignmentInfo = null;
            DB::transaction(function () use ($ticket, $user, $ticketService, &$assignmentInfo) {
                $ticketService->addNote($ticket, $user, "Content report escalated by {$user->name}.");
                $ticketService->changePriority($ticket, TicketPriority::Urgent, $user);

                $assignmentInfo = $this->findPlatformAdminForEscalation($user);
                ['admin' => $platformAdmin] = $assignmentInfo;

                if ($platformAdmin->id !== $user->id) {
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
            Log::error('Failed to escalate content report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Escalation failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Resolve the reported user from the ticket metadata.
     * For user reports: directly from entity_id.
     * For game/campaign reports: from the entity's owner relationship.
     */
    protected function resolveReportedUser(Ticket $ticket, ?string $entityType): ?User
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
     * metadata. Used by the cover-takedown flow (performClearCover + the
     * "Clear Cover Image" action visibility check + the cover preview entry)
     * to load the actual record carrying the host-uploaded cover.
     *
     * @return Model|Game|Campaign|User|null
     */
    protected function resolveReportedEntity(Ticket $ticket, ?string $entityType)
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
    protected function removeGame(?string $entityId): bool
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
    protected function removeCampaign(?string $entityId): bool
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
     * Perform BGG sync using the bgg_url from ticket metadata.
     */
    protected function performBggSync(Ticket $ticket): void
    {
        try {
            $service = app(GameSystemRequestService::class);
            $gameSystem = $service->syncBggFromTicket($ticket);

            Notification::make()
                ->success()
                ->title('BGG sync complete')
                ->body("GameSystem \"{$gameSystem->name}\" has been synced from BGG.")
                ->send();

            Log::info('BGG sync completed from ticket ViewTicket page', [
                'ticket_id' => $ticket->id,
                'game_system_id' => $gameSystem->id,
                'game_system_name' => $gameSystem->name,
            ]);

        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->warning()
                ->title('Cannot sync')
                ->body($e->getMessage())
                ->send();
        } catch (\Throwable $e) {
            Log::error('BGG sync failed from ticket ViewTicket page', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('BGG sync failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Perform BGG sync using a specific BGG ID (from search).
     */
    protected function performBggSyncById(int $bggId): void
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        try {
            $result = app(BggSyncService::class)->syncGameSystems([$bggId]);

            if ($result->failed > 0 && $result->synced === 0) {
                throw new \RuntimeException(
                    'BGG sync failed: '.implode('; ', $result->errors)
                );
            }

            $gameSystem = GameSystem::where('bgg_id', $bggId)->first();

            if (! $gameSystem) {
                throw new \RuntimeException("BGG sync completed but GameSystem not found for bgg_id={$bggId}.");
            }

            // Update ticket metadata with bgg_url and game_system_id
            $metadata = $ticket->metadata ?? [];
            $metadata['bgg_url'] = "https://boardgamegeek.com/boardgame/{$bggId}";
            $metadata['game_system_id'] = $gameSystem->id;
            $ticket->updateQuietly(['metadata' => $metadata]);

            Notification::make()
                ->success()
                ->title('BGG sync complete')
                ->body("GameSystem \"{$gameSystem->name}\" has been synced from BGG.")
                ->send();

            Log::info('BGG sync completed from ticket search', [
                'ticket_id' => $ticket->id,
                'bgg_id' => $bggId,
                'game_system_id' => $gameSystem->id,
                'game_system_name' => $gameSystem->name,
            ]);

        } catch (\Throwable $e) {
            Log::error('BGG sync from search failed', [
                'ticket_id' => $ticket->id,
                'bgg_id' => $bggId,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('BGG sync failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Perform manual GameSystem creation from ticket metadata.
     */
    protected function performManualCreate(Ticket $ticket): void
    {
        try {
            $service = app(GameSystemRequestService::class);
            $gameSystem = $service->createManualFromTicket($ticket);

            Notification::make()
                ->success()
                ->title('GameSystem created')
                ->body("GameSystem \"{$gameSystem->name}\" has been created manually.")
                ->send();

        } catch (\Throwable $e) {
            Log::error('Manual GameSystem creation failed from ticket page', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Creation failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Perform a BGG search and store results in component state.
     */
    protected function performBggSearch(string $query): void
    {
        // Clear previous selection before running new search
        $this->selectedBggId = null;
        $this->selectedBggName = null;
        $this->bggPreviewData = null;

        try {
            $results = app(BggSyncService::class)->search($query);

            $this->bggSearchResults = $results;

            if (empty($results)) {
                Notification::make()
                    ->info()
                    ->title('No results')
                    ->body("No BGG results found for \"{$query}\".")
                    ->send();
            } else {
                Notification::make()
                    ->success()
                    ->title('Search complete')
                    ->body(count($results).' result(s) found.')
                    ->send();
            }
        } catch (\Throwable $e) {
            $this->bggSearchResults = [];
            $this->selectedBggId = null;
            $this->selectedBggName = null;
            $this->bggPreviewData = null;

            Notification::make()
                ->danger()
                ->title('BGG Search Failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Fetch full BGG thing data and store it for preview display.
     */
    protected function fetchBggPreview(int $bggId): void
    {
        try {
            $this->bggPreviewData = app(BggSyncService::class)->previewGameSystem($bggId);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch BGG preview data', [
                'bgg_id' => $bggId,
                'error' => $e->getMessage(),
            ]);
            $this->bggPreviewData = null;

            Notification::make()
                ->warning()
                ->title('Preview unavailable')
                ->body('Could not fetch full BGG data for preview. You can still sync the game.')
                ->send();
        }
    }

    /**
     * Render the BGG search results as an HTML table with Select buttons.
     */
    protected function renderSearchResultsTable(): HtmlString
    {
        if (empty($this->bggSearchResults)) {
            return new HtmlString('');
        }

        $rows = '';
        foreach ($this->bggSearchResults as $index => $result) {
            $isSelected = $this->selectedBggId === $result['bgg_id'];
            $selectedBadge = $isSelected
                ? ' <span class="inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-400">Selected</span>'
                : '';

            $typeLabel = match ($result['bgg_type']) {
                'boardgame' => 'Board Game',
                'boardgameexpansion' => 'Expansion',
                'boardgameaccessory' => 'Accessory',
                default => $result['bgg_type'],
            };

            $selectButton = $isSelected
                ? '<span class="text-primary-600 dark:text-primary-400 text-xs font-medium">✓ Selected</span>'
                : '<button type="button" onclick="Livewire.dispatch(\'selectBggResult\', { index: '.$index.' })" class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">Select</button>';

            $rows .= '<tr class="border-b border-gray-100 dark:border-gray-700">'
                .'<td class="px-3 py-2 text-sm">'.e($result['name']).$selectedBadge.'</td>'
                .'<td class="px-3 py-2 text-sm text-center">'.($result['year_released'] ?? '—').'</td>'
                .'<td class="px-3 py-2 text-sm">'.e($typeLabel).'</td>'
                .'<td class="px-3 py-2 text-sm text-center font-mono">'.$result['bgg_id'].'</td>'
                .'<td class="px-3 py-2 text-sm text-center">'.$selectButton.'</td>'
                .'</tr>';
        }

        return new HtmlString(
            '<div class="overflow-x-auto">'
            .'<table class="w-full text-left text-sm">'
            .'<thead class="border-b border-gray-200 bg-gray-50 dark:bg-gray-800">'
            .'<tr>'
            .'<th class="px-3 py-2 font-medium">Name</th>'
            .'<th class="px-3 py-2 font-medium text-center">Year</th>'
            .'<th class="px-3 py-2 font-medium">Type</th>'
            .'<th class="px-3 py-2 font-medium text-center">BGG ID</th>'
            .'<th class="px-3 py-2 font-medium text-center">Action</th>'
            .'</tr>'
            .'</thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'</table>'
            .'</div>'
        );
    }
}
