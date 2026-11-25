{{-- Name + description (translatable, locale-aware).
     Shared by the create form and the edit mode (D315): included with no argument array, so it
     reads the including view's scope — $activeLocale, $errors. --}}
    {{-- Translatable fields (name + description) rendered via locale-aware section --}}
    @php($allLocales = $this->getAllLocales())
    @php($baselineLocale = $this->getBaselineLocale())
    <x-forms.translatable-section
        :fields="[
            ['name' => 'name', 'label' => __('campaigns.field_session_name'), 'placeholder' => __('games.placeholder_game_name')],
            ['name' => 'description', 'label' => __('common.field_description'), 'type' => 'textarea', 'rows' => 3, 'placeholder' => __('games.placeholder_game_description')],
        ]"
        :active-locale="$activeLocale"
        :baseline-locale="$baselineLocale"
        :all-locales="$allLocales"
        :required="['name']"
        inputClass="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"
    />
