<?php

namespace App\Traits;

use App\Enums\ContentLanguage;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Provides locale-aware translation editing for Livewire form components.
 *
 * The trait manages three stores:
 * 1. **Baseline fields** — the primary-locale values bound to wire:model on the
 *    component's regular public properties (e.g. $name, $description).
 * 2. **pendingTranslations** — an in-memory array keyed as [locale][field] => value,
 *    holding secondary-locale edits until the form is submitted.
 * 3. **baselineFieldsLocale** — the locked anchor naming the locale the baseline
 *    fields hold text for right now; only moveBaselineTo() moves it.
 *
 * Livewire actions provided:
 * - switchLocale(string $locale) — swaps the active locale tab
 * - copyFromBaseline(string $field) — copies baseline value into the active locale
 *
 * @property string $activeLocale Tracks which locale tab is currently displayed.
 * @property string $baselineFieldsLocale The locale the baseline fields hold text for right now.
 * @property array<string, array<string, string>> $pendingTranslations [locale => [field => value]]
 */
trait BuildsTranslatableFormFields
{
    // ── Public Livewire Properties ─────────────────────
    // These MUST be declared on the component OR here as trait-level defaults.
    // Livewire reflects them automatically.

    public string $activeLocale = '';

    /**
     * The locale the baseline fields hold text for RIGHT NOW: set by
     * loadTranslatableValues() to the locale the live properties were filled
     * from; moveBaselineTo() moves it together with them. Locked like an edit
     * form's $originalDateTime — it is server state, and a client-supplied
     * value equal to the live `language` would silently skip the baseline move.
     */
    #[Locked]
    public string $baselineFieldsLocale = '';

    /** @var array<string, array<string, string>> */
    public array $pendingTranslations = [];

    // ── Livewire Actions ───────────────────────────────

    /**
     * Switch the active locale tab. Saves current pending values before swapping.
     *
     * When switching away from the baseline locale, current baseline field values
     * are snapshotted into pendingTranslations. When switching to the baseline,
     * pending values are restored to the component's public properties.
     */
    public function switchLocale(string $locale): void
    {
        $baselineLocale = $this->getBaselineLocale();
        $translatableFields = $this->getTranslatableFields();

        // If we're leaving baseline, snapshot current field values into pendingTranslations
        if ($this->activeLocale === $baselineLocale || $this->activeLocale === '') {
            foreach ($translatableFields as $field) {
                if (property_exists($this, $field)) {
                    data_set($this, "pendingTranslations.{$baselineLocale}.{$field}", $this->getStringProperty($field));
                }
            }
        }

        $this->activeLocale = $locale;

        // If switching to baseline, restore baseline values to component properties
        if ($locale === $baselineLocale) {
            foreach ($translatableFields as $field) {
                $saved = data_get($this, "pendingTranslations.{$baselineLocale}.{$field}");
                if ($saved !== null && property_exists($this, $field)) {
                    data_set($this, $field, $saved);
                }
            }
        }
    }

    /**
     * Copy the baseline locale value for a field into the active locale's pending store.
     */
    public function copyFromBaseline(string $field): void
    {
        $baselineLocale = $this->getBaselineLocale();

        // Get the current baseline value from snapshot or live property
        $baselineValue = data_get($this, "pendingTranslations.{$baselineLocale}.{$field}")
            ?? $this->getStringProperty($field);

        if ($this->activeLocale !== '' && $this->activeLocale !== $baselineLocale) {
            data_set($this, "pendingTranslations.{$this->activeLocale}.{$field}", $baselineValue);
        }
    }

    // ── Configuration Methods (override on component) ──

    /**
     * Return the translatable field names for this form.
     * Override in the component to specify which fields are translatable.
     *
     * @return string[]
     */
    public function getTranslatableFields(): array
    {
        // Default: attempt to read from model's $translatable property.
        // Components should override this if they need a different set.
        return [];
    }

    // ── Trait Helper Methods ───────────────────────────

    /**
     * Get the baseline locale for this form (the event/game/etc.'s primary language).
     * Defaults to the component's $language property or app locale.
     */
    protected function getBaselineLocale(): string
    {
        if (property_exists($this, 'language') && $this->language !== '') {
            return $this->language;
        }

        return app()->getLocale();
    }

    /**
     * Get secondary locales (all configured locales except baseline).
     *
     * @return string[]
     */
    protected function getSecondaryLocales(): array
    {
        $baseline = $this->getBaselineLocale();

        $locales = config('app.available_locales', ['en']);
        if (! is_array($locales)) {
            $locales = ['en'];
        }
        /** @var string[] $locales */

        return array_values(array_filter(
            $locales,
            fn (string $locale) => $locale !== $baseline,
        ));
    }

    /**
     * Get all locales (baseline first, then secondaries).
     *
     * @return string[]
     */
    public function getAllLocales(): array
    {
        return array_merge([$this->getBaselineLocale()], $this->getSecondaryLocales());
    }

    /**
     * Get a human-readable label for a locale code.
     */
    public function getLocaleLabel(string $locale): string
    {
        return ContentLanguage::tryFrom($locale)?->label() ?? strtoupper($locale);
    }

    /**
     * Check whether the given locale is the current baseline.
     */
    public function isBaselineLocale(string $locale): bool
    {
        return $locale === $this->getBaselineLocale();
    }

    /**
     * Get the current value for a translatable field in the active locale.
     * For baseline locale, returns the component property directly.
     * For secondary locales, returns from pendingTranslations.
     */
    public function getTranslatableFieldValue(string $field): string
    {
        $baselineLocale = $this->getBaselineLocale();

        if ($this->activeLocale === $baselineLocale || $this->activeLocale === '') {
            return $this->getStringProperty($field);
        }

        return is_string($result = data_get($this, "pendingTranslations.{$this->activeLocale}.{$field}", '')) ? $result : '';
    }

    // ── Build / Load Methods ───────────────────────────

    /**
     * Assemble the final JSON-compatible translation map per field.
     *
     * Returns [field => [locale => value]] suitable for spatie/laravel-translatable.
     * Only includes non-empty secondary locale values.
     *
     * ⚠️ SIDE EFFECT: Snapshots current baseline values into pendingTranslations
     * so they're available for secondary-locale reference (e.g. copyFromBaseline).
     * This method is not idempotent — calling it twice will overwrite the snapshot.
     *
     * @param  array<int, string>  $fields  Translatable field names
     * @param  string  $baselineLocale  The model's primary locale
     * @param  array<string, mixed>  $input
     * @return array<string, array<string, string>>
     */
    protected function buildTranslatableValues(array $fields, string $baselineLocale, array $input = []): array
    {
        $values = [];

        // Snapshot current baseline values into pendingTranslations so they're
        // available for secondary-locale reference (e.g. copyFromBaseline).
        foreach ($fields as $field) {
            $liveValue = isset($input[$field]) && is_string($input[$field]) ? $input[$field] : $this->getStringProperty($field);
            data_set($this, "pendingTranslations.{$baselineLocale}.{$field}", $liveValue);
        }

        foreach ($fields as $field) {
            // Baseline always comes from the live component property (wire:model writes here)
            $baselineValue = isset($input[$field]) && is_string($input[$field]) ? $input[$field] : $this->getStringProperty($field);
            $translations = [
                $baselineLocale => $baselineValue,
            ];

            foreach ($this->getSecondaryLocalesFor($baselineLocale) as $locale) {
                // Priority: pendingTranslations store → legacy _de property → input
                $value = data_get($this, "pendingTranslations.{$locale}.{$field}");
                if ($value === null || $value === '') {
                    $prop = "{$field}_{$locale}";
                    if (property_exists($this, $prop)) {
                        $value = $this->getStringProperty($prop);
                    } elseif (isset($input[$prop]) && is_string($input[$prop])) {
                        $value = $input[$prop];
                    }
                }
                if ($value !== null && $value !== '') {
                    $translations[$locale] = is_string($value) ? $value : '';
                }
            }
            $values[$field] = $translations;
        }

        return $values;
    }

    /**
     * Load translatable values from a model's stored translations.
     *
     * @param  array<int, string>  $fields
     *                                      Populates pendingTranslations from the model. Also sets legacy _de
     *                                      properties for backward compatibility if they exist on the component.
     */
    protected function loadTranslatableValues(Model $model, array $fields, ?string $overrideLocale = null): void
    {
        assert(method_exists($model, 'getTranslation'));
        $language = $model->getAttributes()['language'] ?? null;
        $primaryLocale = $overrideLocale ?? (is_string($language) ? $language : app()->getLocale());

        // The anchor: the live properties below are filled from THIS locale.
        $this->baselineFieldsLocale = $primaryLocale;

        // Initialize activeLocale
        if ($this->activeLocale === '') {
            $this->activeLocale = $primaryLocale;
        }

        foreach ($fields as $field) {
            // Populate baseline in pendingTranslations
            $baselineValue = $model->getTranslation($field, $primaryLocale) ?? '';
            data_set($this, "pendingTranslations.{$primaryLocale}.{$field}", $baselineValue);

            // Set the component property for baseline
            if (property_exists($this, $field)) {
                data_set($this, $field, $baselineValue);
            }

            // Populate secondary locales
            foreach ($this->getSecondaryLocalesFor($primaryLocale) as $locale) {
                $value = $model->getTranslation($field, $locale) ?? '';
                data_set($this, "pendingTranslations.{$locale}.{$field}", $value);

                // Legacy: also set _de properties for backward compatibility
                $prop = "{$field}_{$locale}";
                if (property_exists($this, $prop)) {
                    data_set($this, $prop, $value);
                }
            }
        }
    }

    /**
     * Move the baseline to another locale — what a content-language change
     * means on an EDIT form (M084/S07, decision D324): the select says "show
     * me this language's text", never "the text on screen is in this language".
     *
     * A STRICT move; nothing is copied from one locale key to another. The
     * text the baseline fields hold is kept as the PREVIOUS locale's
     * translation and the fields re-fill from the NEW locale's stored one —
     * empty when there is none. Without the move getBaselineLocale() already
     * answers the new locale while the fields still hold the old one's text,
     * so buildTranslatableValues() would label that text as the new locale
     * and overwrite its stored translation.
     *
     * The model decides whether the new locale has a translation AT ALL —
     * hasTranslation() reads the STORED map, with no fallback. pendingTranslations
     * cannot answer that alone: loadTranslatableValues() fills a missing locale
     * with spatie's fallback copy of the primary text, which must never be
     * saved as a translation. Where the locale does have a stored translation,
     * its pending value is used: the stored text, or the owner's unsaved edit
     * of it on that locale's tab.
     *
     * Opt-in: call it from an `updatedLanguage()` hook on an EDIT component.
     * A CREATE form's select means "the text I typed is in this language" —
     * there the re-labelling IS the requested behaviour, so it must not call this.
     */
    protected function moveBaselineTo(string $locale, Model $model): void
    {
        assert(method_exists($model, 'hasTranslation'));

        $previous = $this->baselineFieldsLocale;

        if ($locale === $previous) {
            return;
        }

        if ($previous !== '') {
            foreach ($this->getTranslatableFields() as $field) {
                if (property_exists($this, $field)) {
                    data_set($this, "pendingTranslations.{$previous}.{$field}", $this->getStringProperty($field));

                    $text = $model->hasTranslation($field, $locale)
                        ? ($this->pendingTranslations[$locale][$field] ?? '')
                        : '';

                    data_set($this, $field, $text);
                    data_set($this, "pendingTranslations.{$locale}.{$field}", $text);
                }
            }
        }

        // The baseline tab follows the baseline; a secondary tab stays where it is.
        if ($this->activeLocale === '' || $this->activeLocale === $previous) {
            $this->activeLocale = $locale;
        }

        $this->baselineFieldsLocale = $locale;
    }

    /**
     * Empty the pending slots of every SECONDARY locale the model has no
     * stored translation for — so an untouched save of a one-language entity
     * writes nothing and reports nothing (M084/S08, decision D326).
     *
     * loadTranslatableValues() fills pendingTranslations for each secondary
     * locale with $model->getTranslation($field, $locale), and spatie answers
     * that with the PRIMARY text when the locale has none — never null.
     * buildTranslatableValues() then submits [baseline => text, secondary =>
     * copy], which reads as a change: the copy is stored under the other
     * locale, the fields land in changed_fields, and approved participants are
     * told they changed when they did not. With the slot empty, the build drops
     * it ("only non-empty secondary values") and the service compares the
     * stored map against itself: its no-op path.
     *
     * hasTranslation() is the only authority here — it reads the STORED map,
     * with no fallback. Never getTranslation(): it answers the fallback copy,
     * which is exactly the value being pruned. The baseline locale is never
     * touched: its text is real and sits in the fields the owner is editing.
     *
     * Opt-in: call it from an EDIT component's mount(), directly after
     * loadTranslatableValues(). The other consumers of this trait (the create
     * forms, ManageEvent, EventAnnouncements, the team components) keep the
     * fallback copy until follow-up R111. An owner sees the difference on the
     * other locale's tab of a one-language entity: it now opens EMPTY instead
     * of on a copy of the primary text — copyFromBaseline() still copies on an
     * explicit click.
     *
     * @param  array<int, string>  $fields  Translatable field names
     */
    protected function pruneUnstoredTranslations(Model $model, array $fields): void
    {
        assert(method_exists($model, 'hasTranslation'));

        foreach ($this->getSecondaryLocalesFor($this->baselineFieldsLocale) as $locale) {
            foreach ($fields as $field) {
                if ($model->hasTranslation($field, $locale)) {
                    continue;
                }

                data_set($this, "pendingTranslations.{$locale}.{$field}", '');

                // Legacy: the component's `{field}_{locale}` property, where it declares one.
                $legacyProperty = "{$field}_{$locale}";
                if (property_exists($this, $legacyProperty)) {
                    data_set($this, $legacyProperty, '');
                }
            }
        }
    }

    /**
     * Generate validation rules for secondary locale fields.
     * Supports both legacy _de properties and the new pendingTranslations store.
     *
     * @param  array<string, mixed>  $fieldRules  [field => baseRules]
     * @return array<string, mixed>
     */
    protected function translatableValidationRules(array $fieldRules, string $primaryLanguage): array
    {
        $rules = [];
        foreach ($fieldRules as $field => $baseRules) {
            $rules[$field] = $baseRules;

            // Derive secondary-locale rules from the base rules:
            // replace 'required' with 'nullable' (secondary locales are optional)
            // but preserve all other constraints (max, min, etc.).
            // Non-string rules (objects, closures) pass through unchanged.
            $baseRulesArray = is_array($baseRules) ? $baseRules : (is_string($baseRules) ? explode('|', $baseRules) : []);
            $secondaryRules = collect($baseRulesArray)
                ->map(fn ($rule) => is_string($rule) && $rule === 'required' ? 'nullable' : $rule)
                ->values()
                ->all();

            foreach ($this->getSecondaryLocalesFor($primaryLanguage) as $locale) {
                // Legacy: add rules for _de properties if they exist
                $prop = "{$field}_{$locale}";
                if (property_exists($this, $prop)) {
                    $rules[$prop] = $secondaryRules;
                }

                // New: add rules for pendingTranslations nested keys
                $rules["pendingTranslations.{$locale}.{$field}"] = $secondaryRules;
            }
        }

        return $rules;
    }

    // ── Internal Helpers ───────────────────────────────

    /**
     * Get a string property value from the component, returning default if not set.
     * Handles dynamic property access safely for PHPStan level 9.
     */
    private function getStringProperty(string $field, string $default = ''): string
    {
        if (! property_exists($this, $field)) {
            return $default;
        }
        $value = data_get($this, $field);

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : $default);
    }

    /**
     * Get secondary locales for a specific baseline locale.
     *
     * @return string[]
     */
    protected function getSecondaryLocalesFor(string $baselineLocale): array
    {
        $locales = config('app.available_locales', ['en']);
        if (! is_array($locales)) {
            $locales = ['en'];
        }
        /** @var string[] $locales */

        return array_values(array_filter(
            $locales,
            fn (string $locale) => $locale !== $baselineLocale,
        ));
    }
}
