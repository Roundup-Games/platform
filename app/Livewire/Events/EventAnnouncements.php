<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
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
    use BuildsTranslatableFormFields;
    use WithPagination;

    public Event $event;

    // ── Form State ────────────────────────────────────
    public bool $showForm = false;

    public ?string $editingId = null;

    // Inherit the event's language as the baseline locale for translations.
    // The BuildsTranslatableFormFields trait's getBaselineLocale() reads this.
    public string $language = 'en';

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $content = '';

    // ── Translatable fields ──
    /**
     * @return array<int, string>
     */
    public function getTranslatableFields(): array
    {
        return ['title', 'content'];
    }

    #[Validate('boolean')]
    public bool $is_pinned = false;

    #[Validate('boolean')]
    public bool $is_published = false;

    // ── Filters ───────────────────────────────────────
    public string $filterStatus = ''; // all, published, draft

    public ?string $confirmingAction = null;

    public function mount(string $slug): void
    {
        $this->event = Event::where('slug', $slug)->firstOrFail();
        $this->authorize('update', $this->event);

        // Inherit the event's language so the locale switcher and translation
        // loading use the correct baseline locale (not app()->getLocale()).
        $this->language = $this->event->language ?? 'en';
    }

    // ── Computed ──────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, EventAnnouncement>
     */
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

    /**
     * @return array<string, int>
     */
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
        $this->is_pinned = $announcement->is_pinned;
        $this->is_published = $announcement->is_published;
        $this->showForm = true;

        // Load secondary locale translations (uses event's language)
        $this->loadTranslatableValues($announcement, ['title', 'content'], $this->event->language);
    }

    public function save(): void
    {
        $this->validate();
        $this->authorize('update', $this->event);

        $primaryLanguage = $this->event->language ?? 'en';
        $this->validate(
            $this->translatableValidationRules(
                ['title' => 'required|string|max:255', 'content' => 'required|string'],
                $primaryLanguage,
            ),
        );

        $translatable = $this->buildTranslatableValues(
            ['title', 'content'],
            $primaryLanguage,
            ['title' => $this->title, 'content' => $this->content],
        );

        if ($this->editingId) {
            $announcement = $this->findAnnouncement($this->editingId);
            $announcement->update([
                'title' => $translatable['title'],
                'content' => $translatable['content'],
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
                'title' => $translatable['title'],
                'content' => $translatable['content'],
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
        $this->is_pinned = false;
        $this->is_published = false;
    }

    private function findAnnouncement(string $id): EventAnnouncement
    {
        return EventAnnouncement::where('id', $id)
            ->where('event_id', $this->event->id)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.events.event-announcements');
    }
}
