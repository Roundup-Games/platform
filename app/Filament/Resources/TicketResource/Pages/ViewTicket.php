<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\VenueType;
use App\Filament\Resources\GameSystemResource;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Actions\ContentReport;
use App\Filament\Resources\TicketResource\Actions\DataExport;
use App\Filament\Resources\TicketResource\Actions\GameSystemRequest;
use App\Filament\Resources\TicketResource\Actions\ReviewModeration;
use App\Filament\Resources\TicketResource\Actions\VenueClaim;
use App\Filament\Resources\TicketResource\Actions\VenueProposal;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\BggSyncService;
use Escalated\Filament\Resources\TicketResource\Pages\ViewTicket as BaseViewTicket;
use Escalated\Laravel\Contracts\TicketSubject;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Models\TicketSubjectLink;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
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
     * The current BGG search query, synced live from the modal TextInput.
     *
     * Stored as a public property (not read from Filament form state) because
     * the search footer action is a standalone Action with no schema-component
     * binding — Filament's Get/$data injection doesn't work for modal footer
     * actions. This mirrors the established pattern used by $bggSearchResults,
     * $selectedBggId, and $selectedBggName for cross-field communication.
     */
    public ?string $bggSearchQuery = null;

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
            // Use the 'og' conversion (1200x630) for the admin review preview so
            // the infolist renders a reasonably-sized image rather than the
            // full-size original (up to 4096x4096 / 5 MB).
            $coverUrl = $coverEntity->resolveCoverUrl('og');
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

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();

        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        // Domain actions self-gate: each factory returns [] for tickets outside
        // its domain. The business logic (transactions, toasts, logging) lives in
        // the matching action class under TicketResource/Actions/. Insertion
        // order preserved from the former inline builders (each group was
        // spliced at index 0, so later groups end up front-most).
        $groups = [
            VenueProposal::headerActions($ticket),
            VenueClaim::headerActions($ticket),
            DataExport::headerActions($ticket),
            ContentReport::headerActions($ticket),
            ReviewModeration::headerActions($ticket),
            GameSystemRequest::headerActions($this, $ticket),
        ];

        foreach ($groups as $groupActions) {
            if (! empty($groupActions)) {
                array_splice($actions, 0, 0, $groupActions);
            }
        }

        return $actions;
    }

    /**
     * Select a BGG search result by index.
     * Called from the rendered results table via the row's wire:click.
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

    /*
     * ---------------------------------------------------------------------
     * Moderation reflection seams.
     *
     * ContentModerationTest, CoverImageTakedownTest, and
     * EscalatedReviewReportTest drive the moderation flows by invoking these
     * protected methods on the page via reflection (invokeModerationAction).
     * The implementations moved to the per-domain action classes; these thin
     * delegates keep that contract working.
     * ---------------------------------------------------------------------
     */

    /**
     * Dismiss the report: close ticket, keep review published.
     */
    protected function performDismissReport(Ticket $ticket): void
    {
        ReviewModeration::dismissReport($ticket);
    }

    /**
     * Remove the review: close ticket, hide review.
     */
    protected function performRemoveReview(Ticket $ticket): void
    {
        ReviewModeration::removeReview($ticket);
    }

    /**
     * Escalate review report: reassign to a Platform Admin, priority to Urgent.
     */
    protected function performEscalateReport(Ticket $ticket): void
    {
        ReviewModeration::escalateReport($ticket);
    }

    /**
     * Dismiss content report: close ticket with no action on the reported entity.
     */
    protected function performDismissContentReport(Ticket $ticket): void
    {
        ContentReport::dismissReport($ticket);
    }

    /**
     * Warn user: close ticket, send warning notification to the content owner.
     */
    protected function performWarnUser(Ticket $ticket, ?string $entityType, ?string $entityName, ?string $note): void
    {
        ContentReport::warnUser($ticket, $entityType, $entityName, $note);
    }

    /**
     * Clear ONLY the host-uploaded cover image on a reported game/campaign.
     */
    protected function performClearCover(Ticket $ticket, ?string $entityType, ?string $entityName): void
    {
        ContentReport::clearCover($ticket, $entityType, $entityName);
    }

    /**
     * Remove content: close ticket, hide/remove the reported entity, notify owner.
     */
    protected function performRemoveContent(Ticket $ticket, ?string $entityType, ?string $entityName): void
    {
        ContentReport::removeContent($ticket, $entityType, $entityName);
    }

    /**
     * Suspend user: close ticket, disable the reported user account, notify user.
     */
    protected function performSuspendUser(Ticket $ticket, ?string $entityType): void
    {
        ContentReport::suspendUser($ticket, $entityType);
    }

    /**
     * Escalate content report: reassign to Platform Admin, increase priority to Urgent.
     */
    protected function performEscalateContentReport(Ticket $ticket): void
    {
        ContentReport::escalateReport($ticket);
    }

    /**
     * Render the BGG search results table via a Blade view.
     *
     * Rows come from third-party BGG XML, so every cell is escaped in the
     * view ({{ }}) and the Select button uses wire:click instead of inline
     * onclick JS.
     */
    public function renderSearchResultsTable(): HtmlString
    {
        if (empty($this->bggSearchResults)) {
            return new HtmlString('');
        }

        return new HtmlString(
            view('filament.tickets.bgg-search-table', [
                'results' => $this->bggSearchResults,
                'selectedBggId' => $this->selectedBggId,
            ])->render(),
        );
    }
}
