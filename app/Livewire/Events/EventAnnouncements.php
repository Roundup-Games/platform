<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventAnnouncement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class EventAnnouncements extends Component
{
    use WithPagination;

    public Event $event;

    // ── Form State ────────────────────────────────────
    public bool $showForm = false;
    public ?string $editingId = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $content = '';

    #[Validate('boolean')]
    public bool $is_pinned = false;

    #[Validate('boolean')]
    public bool $is_published = false;

    // ── Translation fields ────────────────────────────
    public string $title_de = '';
    public string $content_de = '';
    public string $activeLocale = 'en';

    // ── Filters ───────────────────────────────────────
    public string $filterStatus = ''; // all, published, draft

    public function mount(string $slug): void
    {
        $this->event = Event::where('slug', $slug)->firstOrFail();
        $this->authorize('update', $this->event);
    }

    // ── Computed ──────────────────────────────────────

    #[Computed]
    public function announcements()
    {
        $query = EventAnnouncement::where('event_id', $this->event->id)
            ->with('author');

        if ($this->filterStatus === 'published') {
            $query->where('is_published', true);
        } elseif ($this->filterStatus === 'draft') {
            $query->where('is_published', false);
        }

        return $query->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    #[Computed]
    public function counts(): array
    {
        $base = EventAnnouncement::where('event_id', $this->event->id);

        return [
            'total' => $base->count(),
            'published' => (clone $base)->where('is_published', true)->count(),
            'draft' => (clone $base)->where('is_published', false)->count(),
            'pinned' => (clone $base)->where('is_pinned', true)->count(),
        ];
    }

    // ── Form Actions ──────────────────────────────────

    public function showCreateForm(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function editAnnouncement(string $id): void
    {
        $announcement = $this->findAnnouncement($id);

        $this->editingId = $id;
        $this->title = $announcement->title;
        $this->content = $announcement->content;
        $this->title_de = $announcement->getTranslation('de', 'title') ?? '';
        $this->content_de = $announcement->getTranslation('de', 'content') ?? '';
        $this->is_pinned = $announcement->is_pinned;
        $this->is_published = $announcement->is_published;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();
        $this->validateTranslationFields();
        $this->authorize('update', $this->event);

        if ($this->editingId) {
            $announcement = $this->findAnnouncement($this->editingId);
            $announcement->update([
                'title' => $this->title,
                'content' => $this->content,
                'is_pinned' => $this->is_pinned,
                'is_published' => $this->is_published,
            ]);

            Log::info('Event announcement updated', [
                'announcement_id' => $announcement->id,
                'event_id' => $this->event->id,
                'updated_by' => Auth::id(),
            ]);
        } else {
            $announcement = EventAnnouncement::create([
                'event_id' => $this->event->id,
                'author_id' => Auth::id(),
                'title' => $this->title,
                'content' => $this->content,
                'is_pinned' => $this->is_pinned,
                'is_published' => $this->is_published,
            ]);

            Log::info('Event announcement created', [
                'announcement_id' => $announcement->id,
                'event_id' => $this->event->id,
                'is_published' => $this->is_published,
                'created_by' => Auth::id(),
            ]);
        }

        // Persist DE translations when event content_language includes German
        $contentLanguage = $this->event->content_language ?? 'en';
        if (in_array($contentLanguage, ['de', 'de+en'])) {
            $announcement->setTranslation('de', 'title', $this->title_de);
            $announcement->setTranslation('de', 'content', $this->content_de);
        }

        $this->resetForm();
        unset($this->announcements, $this->counts);

        session()->flash('success', $this->editingId ? __('events.flash_announcement_updated') : __('events.flash_announcement_created'));
    }

    // ── Quick Actions ─────────────────────────────────

    public function publishAnnouncement(string $id): void
    {
        $announcement = $this->findAnnouncement($id);
        $this->authorize('update', $this->event);

        $announcement->update(['is_published' => true]);

        Log::info('Event announcement published', [
            'announcement_id' => $id,
            'event_id' => $this->event->id,
            'published_by' => Auth::id(),
        ]);

        unset($this->announcements, $this->counts);
        session()->flash('success', __('events.flash_announcement_published'));
    }

    public function unpublishAnnouncement(string $id): void
    {
        $announcement = $this->findAnnouncement($id);
        $this->authorize('update', $this->event);

        $announcement->update(['is_published' => false]);

        Log::info('Event announcement unpublished', [
            'announcement_id' => $id,
            'event_id' => $this->event->id,
            'unpublished_by' => Auth::id(),
        ]);

        unset($this->announcements, $this->counts);
        session()->flash('success', __('events.flash_announcement_unpublished'));
    }

    public function togglePin(string $id): void
    {
        $announcement = $this->findAnnouncement($id);
        $this->authorize('update', $this->event);

        $announcement->update(['is_pinned' => ! $announcement->is_pinned]);

        Log::info('Event announcement pin toggled', [
            'announcement_id' => $id,
            'event_id' => $this->event->id,
            'is_pinned' => ! $announcement->is_pinned,
            'toggled_by' => Auth::id(),
        ]);

        unset($this->announcements, $this->counts);
    }

    public function deleteAnnouncement(string $id): void
    {
        $announcement = $this->findAnnouncement($id);
        $this->authorize('update', $this->event);

        Log::info('Event announcement deleted', [
            'announcement_id' => $id,
            'event_id' => $this->event->id,
            'deleted_by' => Auth::id(),
        ]);

        $announcement->delete();

        unset($this->announcements, $this->counts);
        session()->flash('success', __('events.flash_announcement_deleted'));
    }

    // ── Filters ───────────────────────────────────────

    public function setFilterStatus(string $status): void
    {
        $this->filterStatus = $status;
        $this->resetPage();
    }

    // ── Helpers ───────────────────────────────────────

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->title = '';
        $this->content = '';
        $this->title_de = '';
        $this->content_de = '';
        $this->activeLocale = 'en';
        $this->is_pinned = false;
        $this->is_published = false;
    }

    public function setLocaleTab(string $locale): void
    {
        $this->activeLocale = $locale;
    }

    /**
     * Validate DE translation fields when event content_language includes German.
     */
    protected function validateTranslationFields(): void
    {
        $contentLanguage = $this->event->content_language ?? 'en';

        if (! in_array($contentLanguage, ['de', 'de+en'])) {
            return;
        }

        $this->validate(
            [
                'title_de' => 'required|string|max:255',
                'content_de' => 'required|string',
            ],
            [
                'title_de.required' => 'The German title is required because this event\'s content language includes German.',
                'content_de.required' => 'The German content is required because this event\'s content language includes German.',
            ]
        );
    }

    private function findAnnouncement(string $id): EventAnnouncement
    {
        return EventAnnouncement::where('id', $id)
            ->where('event_id', $this->event->id)
            ->firstOrFail();
    }

    public function render()
    {
        return view('livewire.events.event-announcements');
    }
}
