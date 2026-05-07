{{-- Avatar Display + Upload Form --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 mb-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">account_circle</span>
        {{ __('profile.field_avatar') }}
    </h2>

    <div class="flex items-center gap-5">
        <div class="shrink-0">
            <x-user-avatar :user="auth()->user()" size="w-16 h-16 sm:w-20 sm:h-20" text-size="text-xl sm:text-2xl" />
        </div>
        <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <label class="cursor-pointer inline-flex items-center gap-1.5 px-3.5 py-2 bg-surface-container-high text-on-surface-variant rounded-lg text-sm font-medium hover:bg-surface-container transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">upload</span>
                    <span>{{ __('common.field_choose_photo') }}</span>
                    <input type="file" wire:model="avatar" accept="image/*" class="hidden" />
                </label>

                @if($avatarMedia)
                    <button wire:click="removeAvatar" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 text-sm text-error hover:brightness-110 transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">delete</span>
                        {{ __('common.action_remove') }}
                    </button>
                @endif
            </div>

            @error('avatar')
                <p class="mt-2 text-sm text-error">{{ $message }}</p>
            @enderror

            @if($avatar)
                <div class="mt-3 flex items-center gap-3">
                    @php
                        $previewUrl = null;
                        try { $previewUrl = $avatar->temporaryUrl(); } catch (\Throwable $e) {}
                    @endphp
                    @if($previewUrl)
                        <img src="{{ $previewUrl }}" alt="Preview" class="w-12 h-12 rounded-full object-cover ring-2 ring-outline-variant/30" />
                    @endif
                    <span class="text-xs text-on-surface-variant truncate">{{ $avatar->getClientOriginalName() }}</span>
                </div>
            @endif

            <p class="mt-2 text-xs text-on-surface-variant/60">{{ __('common.content_jpg_png_or_gif_max_1mb') }}</p>
        </div>
    </div>
</section>
