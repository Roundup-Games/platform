{{-- Custom reminders (host affordance — decision D125) --}}
{{-- Organizer-authored extra session reminders, up to 5 per game. The built-in --}}
{{-- 24h/1h reminders are unaffected; these rows are swept by the custom pass --}}
{{-- in SendSessionReminders (T06) through the SessionReminder notification. --}}

@php
    $canEdit = $isOwner
        && $game->status->value === 'scheduled';
@endphp

@if($canEdit)
    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6"
             x-data="{ showForm: {{ $editingReminderId ? 'true' : 'false' }} }">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">notifications_active</span>
                {{ __('games.title_custom_reminders') }}
            </h2>
            <span class="text-sm text-on-surface-variant">
                {{ __('games.label_reminder_count', ['count' => $customReminders->count()]) }}
            </span>
        </div>

        <p class="mt-2 text-sm text-on-surface-variant">{{ __('games.hint_custom_reminders') }}</p>

        {{-- Existing reminders list --}}
        <ul class="mt-4 space-y-2">
            @forelse($customReminders as $reminder)
                <li class="rounded-lg border border-outline-variant/60 bg-surface p-3">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-on-surface">
                                @if($reminder->send_at)
                                    {{ $reminder->send_at->setTimezone('Europe/Berlin')->format('M j, Y g:i A') }}
                                @else
                                    {{ __('games.error_reminder_send_at_invalid') }}
                                @endif
                                @if($reminder->sent_at)
                                    <span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">check</span>
                                        {{ __('games.label_reminder_sent') }}
                                    </span>
                                @endif
                            </p>
                            @if($reminder->message)
                                <p class="mt-1 text-sm text-on-surface-variant whitespace-pre-line">{{ $reminder->message }}</p>
                            @else
                                <p class="mt-1 text-xs text-on-surface-variant/70 italic">{{ __('games.label_reminder_default_copy') }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" wire:click="editReminder('{{ $reminder->id }}')"
                                @click="showForm = true"
                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md text-primary hover:bg-primary/10 transition-colors">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">edit</span>
                                {{ __('games.action_edit_reminder') }}
                            </button>
                            <x-confirm-action
                                action="removeReminder('{{ $reminder->id }}')"
                                id="remove-reminder-{{ $reminder->id }}"
                                :icon="'delete'"
                                :trigger-label="__('games.action_remove_reminder')"
                                trigger-class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md text-error hover:bg-error/10 transition-colors"
                                :confirm-label="__('games.action_remove_reminder')"
                                :cancel-label="__('common.action_cancel')"
                                :message="__('games.confirm_remove_reminder')"
                                variant="inline"
                                severity="destructive"
                                confirm-icon="delete"
                            />
                        </div>
                    </div>
                </li>
            @empty
                <li class="text-sm text-on-surface-variant italic py-2 text-center">
                    {{ __('games.content_no_custom_reminders') }}
                </li>
            @endforelse
        </ul>

        {{-- Add / edit form --}}
        @if($reminderLimitReached && ! $editingReminderId)
            <p class="mt-4 text-sm text-on-surface-variant bg-surface-container-high rounded-lg p-3">
                {{ __('games.content_reminder_limit_reached') }}
            </p>
        @else
            <div x-show="showForm" x-cloak class="mt-4 rounded-lg border border-outline-variant bg-surface p-4">
                <h3 class="text-sm font-heading font-bold text-on-surface mb-3">
                    {{ $editingReminderId ? __('games.action_edit_reminder') : __('games.title_add_reminder') }}
                </h3>

                <form wire:submit.prevent="saveReminder" class="space-y-3">
                    <div>
                        <label for="reminderSendAt" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('games.label_reminder_send_at') }}
                        </label>
                        <input id="reminderSendAt" type="datetime-local"
                            wire:model="reminderSendAt"
                            class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none" />
                        @error('reminderSendAt')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="reminderMessage" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('games.label_reminder_message') }}
                        </label>
                        <textarea id="reminderMessage" rows="3" maxlength="500"
                            wire:model="reminderMessage"
                            placeholder="{{ __('games.placeholder_reminder_message') }}"
                            class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none"></textarea>
                        @error('reminderMessage')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelReminderForm"
                            @click="showForm = false"
                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-on-surface-variant hover:bg-surface-container-high transition-colors">
                            {{ __('common.action_cancel') }}
                        </button>
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">save</span>
                            {{ __('games.action_save_reminder') }}
                        </button>
                    </div>
                </form>
            </div>

            <div x-show="!showForm" x-cloak class="mt-4">
                <button type="button" @click="showForm = true"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary/10 text-primary hover:bg-primary/20 transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('games.action_add_reminder') }}
                </button>
            </div>
        @endif
    </section>
@endif
