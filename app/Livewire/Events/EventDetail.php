<?php

namespace App\Livewire\Events;

use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.public-layout')]
class EventDetail extends Component
{
    public Event $event;

    public function mount(string $slug): void
    {
        $event = Event::where('slug', $slug)->firstOrFail();
        $this->authorize('view', $event);
        $this->event = $event;
    }

    public function render()
    {
        $this->event->load([
            'translations',
            'announcements.translations',
            'announcements' => fn ($q) => $q->published()->orderByDesc('is_pinned')->orderByDesc('created_at'),
            'registrations',
        ]);

        $locale = app()->getLocale();
        $translated = $this->getTranslatedContent($this->event, $locale);

        // Translate announcements
        $translatedAnnouncements = $this->event->announcements->map(function ($announcement) use ($locale) {
            $titleTranslation = $announcement->getTranslation($locale, 'title');
            $contentTranslation = $announcement->getTranslation($locale, 'content');

            // Detect fallback for announcements
            $announcementFallback = false;
            if ($titleTranslation !== null && $titleTranslation !== $announcement->getTranslation($locale, 'title')) {
                $announcementFallback = true;
            }

            return (object) [
                'id' => $announcement->id,
                'title' => $titleTranslation ?? $announcement->title,
                'content' => $contentTranslation ?? $announcement->content,
                'is_pinned' => $announcement->is_pinned,
                'created_at' => $announcement->created_at,
                'is_fallback' => $announcementFallback,
            ];
        });

        $counts = $this->event->registrations()
            ->selectRaw('registration_type, count(*) as count')
            ->groupBy('registration_type')
            ->pluck('count', 'registration_type');

        $teamCount = $counts->get('team', 0);
        $individualCount = $counts->get('individual', 0);

        return view('livewire.events.event-detail', [
            'announcements' => $translatedAnnouncements,
            'teamCount' => $teamCount,
            'individualCount' => $individualCount,
            'translatedName' => $translated['name'],
            'translatedShortDescription' => $translated['short_description'],
            'translatedDescription' => $translated['description'],
            'translatedSchedule' => $translated['schedule'],
            'translatedRules' => $translated['rules'],
            'isFallback' => $translated['is_fallback'],
            'fallbackLocale' => $translated['fallback_locale'],
        ]);
    }

    /**
     * Get translated content for the event with fallback detection.
     *
     * For each translatable field, tries the requested locale first,
     * then falls back to the entity's own attribute value. Sets flags
     * when fallback occurs so the view can display a badge.
     */
    private function getTranslatedContent(Event $event, string $locale): array
    {
        $fields = ['name', 'short_description', 'description', 'schedule', 'rules'];
        $result = [];
        $isFallback = false;
        $fallbackLocale = null;

        // Determine the "other" locale for fallback label
        $otherLocale = $locale === 'de' ? 'en' : 'de';

        foreach ($fields as $field) {
            // Check if a translation row exists for this locale+field
            $hasTranslationRow = $event->translations->first(
                fn ($t) => $t->locale === $locale && $t->field === $field
            );

            if ($hasTranslationRow !== null) {
                // Use getTranslation which handles decode logic properly
                $result[$field] = $event->getTranslation($locale, $field);
            } else {
                // No translation row — use entity's own attribute value (fallback)
                $entityValue = $event->getAttribute($field);
                $result[$field] = $entityValue;
                if ($entityValue !== null) {
                    $isFallback = true;
                    $fallbackLocale = $otherLocale;
                }
            }
        }

        $result['is_fallback'] = $isFallback;
        $result['fallback_locale'] = $fallbackLocale;

        return $result;
    }
}
