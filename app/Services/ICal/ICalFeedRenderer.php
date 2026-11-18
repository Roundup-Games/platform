<?php

namespace App\Services\ICal;

use App\Models\Event;
use App\Models\Game;
use App\Services\Calendar\IcsBuilder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Renders the feed owner's schedulable occurrences (Games + registered
 * Events) into a METHOD:PUBLISH iCalendar document (D123 feed, D287/T02
 * upgrade).
 *
 * Pure mapping onto the shared {@see IcsBuilder} — "one ICS writer, two
 * consumers": the builder owns the VCALENDAR envelope, VEVENT shape, UID
 * family (game-{id}@roundup.games / campaign-{id}-session-{gameId}@… /
 * event-{id}@…), folding and escaping; this class contributes only the
 * feed-specific concerns — locale-aware text resolution and start-time
 * ordering. Feed UIDs are the SAME family the iTIP mail attachments use,
 * so a client holding an invitation and subscribed to the feed correlates
 * both to one calendar event. (This intentionally changed the UID scheme
 * from the pre-T02 raw game-UUID UIDs: already-subscribed clients see a
 * one-time duplicate event per game — accepted pre-launch.)
 *
 * The former eluceo/ical rendering is retired from the read path; the
 * composer dependency stays installed (dependency changes need approval).
 */
class ICalFeedRenderer
{
    public function __construct(
        private readonly IcsBuilder $builder,
    ) {}

    /**
     * Render the occurrences into an iCalendar string.
     *
     * @param  Collection<int, Game|Event>  $entities  already scoped to the feed owner; games must have owner + linkedLocation eager-loaded and events their organizer (the builder resolves both per entity).
     * @param  string  $locale  the locale to resolve translatable fields to.
     */
    public function render(Collection $entities, string $locale): string
    {
        $ordered = $entities
            ->sortBy(fn (Game|Event $entity): CarbonInterface => $this->occurrenceStart($entity))
            ->values();

        return $this->builder->publish(
            $ordered,
            fn (Game|Event $entity): array => $this->resolveText($entity, $locale),
        );
    }

    /**
     * The occurrence start used for cross-type ordering (games: scheduled
     * instant; events: calendar day).
     */
    private function occurrenceStart(Game|Event $entity): CarbonInterface
    {
        if ($entity instanceof Event) {
            return $entity->start_date ?? now();
        }

        return $entity->date_time ?? now();
    }

    /**
     * Locale-aware text resolution per entity type.
     *
     * @return array{summary: string, description: string, url: string|null}
     */
    private function resolveText(Game|Event $entity, string $locale): array
    {
        if ($entity instanceof Event) {
            $name = $this->resolveTranslation($entity, 'name', $locale);

            return [
                'summary' => $name,
                'description' => $this->resolveTranslation($entity, 'description', $locale),
                'url' => $entity->slug !== null && $entity->slug !== ''
                    ? route('events.detail', ['slug' => $entity->slug], absolute: true)
                    : null,
            ];
        }

        return [
            'summary' => $this->resolveTranslation($entity, 'name', $locale),
            'description' => $this->resolveDescription($entity, $locale),
            'url' => route('games.detail', $entity, absolute: true),
        ];
    }

    /**
     * Resolve a Spatie-translatable field to a single locale string
     * (fallback any → en is configured globally via
     * Spatie Translatable::fallback).
     */
    private function resolveTranslation(Game|Event $entity, string $field, string $locale): string
    {
        $value = $entity->getTranslation($field, $locale, false);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = $entity->getTranslation($field, 'en');

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * Build the DESCRIPTION from the translated description + optional host note.
     */
    private function resolveDescription(Game $game, string $locale): string
    {
        $description = $this->resolveTranslation($game, 'description', $locale);

        if (is_string($game->host_note) && $game->host_note !== '') {
            $description = trim($description."\n\n".$game->host_note);
        }

        return $description;
    }
}
