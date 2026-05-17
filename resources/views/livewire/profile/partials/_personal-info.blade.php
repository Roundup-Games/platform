{{-- Personal Information + Language/Location Form Sections --}}
{{-- Personal Info --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">badge</span>
        {{ __('common.content_personal_information') }}
    </h2>

    <div class="space-y-4">
        <div>
            <label for="profile-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_name') }}</label>
            <input type="text" id="profile-name" wire:model="name"
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
            @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="profile-slug" class="block text-sm font-medium text-on-surface mb-1">{{ __('profile.field_username') }}</label>
            <div class="flex items-center rounded-lg bg-surface-container-highest/50 border border-transparent px-4 py-2.5 shadow-sm">
                <span class="text-sm text-on-surface-variant select-none">roundup.games/u/</span>
                <span id="profile-slug" class="text-sm text-on-surface">{{ $slug }}</span>
            </div>
            <p class="mt-1 text-xs text-on-surface-variant">{{ __('profile.hint_username_readonly') }}</p>
        </div>

        <div>
            <label for="profile-email" class="block text-sm font-medium text-on-surface mb-1">{{ __('emails.field_email') }}</label>
            <input type="email" id="profile-email" wire:model="email"
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
            @error('email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="profile-gender" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_gender') }}</label>
                <select id="profile-gender" wire:model="gender"
                        class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                    <option value="">{{ __('common.content_select') }}</option>
                    <option value="male">{{ __('common.content_male') }}</option>
                    <option value="female">{{ __('common.content_female') }}</option>
                    <option value="non-binary">{{ __('common.content_non_binary') }}</option>
                    <option value="prefer-not-to-say">{{ __('common.content_prefer_not_to_say') }}</option>
                    <option value="other">{{ __('common.content_other') }}</option>
                </select>
            </div>

            <div>
                <label for="profile-pronouns" class="block text-sm font-medium text-on-surface mb-1">{{ __('profile.content_pronouns') }}</label>
                <select id="profile-pronouns" wire:model="pronouns"
                        class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                    <option value="">{{ __('common.content_select') }}</option>
                    <option value="he/him">{{ __('common.content_he_him') }}</option>
                    <option value="she/her">{{ __('common.content_she_her') }}</option>
                    <option value="they/them">{{ __('common.content_they_them') }}</option>
                    <option value="prefer-not-to-say">{{ __('common.content_prefer_not_to_say') }}</option>
                    <option value="other">{{ __('common.content_other') }}</option>
                </select>
            </div>
        </div>

        {{-- Gender consent management (GDPR Art. 9(2)(a)) --}}
        <div class="p-4 rounded-lg bg-surface-container-high/50 border border-outline-variant/10">
            <div class="flex items-start gap-3">
                <input type="checkbox" id="profile-gender-consent" wire:model="gender_consent"
                       class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary/20" />
                <label for="profile-gender-consent" class="text-xs text-on-surface-variant leading-relaxed cursor-pointer">
                    {{ __('auth.gender_consent_explanation') }}
                </label>
            </div>
            <p class="mt-2 text-xs text-on-surface-variant/70 pl-7">
                {{ __('auth.gender_consent_revocation_note') }}
            </p>
        </div>

        <div>
            <label for="profile-phone" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_phone') }}</label>
            <input type="tel" id="profile-phone" wire:model="phone" placeholder="+49 151 1234567"
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant/50" />
            @error('phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="profile-bio" class="block text-sm font-medium text-on-surface mb-1">{{ __('profile.field_bio') }}</label>
            <textarea id="profile-bio" wire:model="bio" rows="3" maxlength="500"
                      placeholder="{{ __('profile.placeholder_bio') }}"
                      class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant/50 resize-y"></textarea>
            <p class="mt-1 text-xs text-on-surface-variant"><span x-text="$wire.bio?.length || 0"></span>/500</p>
            @error('bio') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    </div>
</section>

{{-- Language & Location --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">language</span>
        {{ __('profile.content_language_location') }}
    </h2>

    <div class="space-y-4">
        <div>
            <label for="profile-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('profile.content_preferred_language') }}</label>
            <select id="profile-language" wire:model="preferredLanguage"
                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                <option value="">{{ __('common.content_select') }}</option>
                @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                    <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                @endforeach
            </select>
            @error('preferredLanguage') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-on-surface mb-1">{{ __('location.content_location') }}</label>
            <livewire:components.location-picker :location-id="$locationId" />
        </div>
    </div>
</section>
