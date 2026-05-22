<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * Provides locale-aware translation editing for Livewire form components.
 *
 * The trait manages two stores:
 * 1. **Baseline fields** — the primary-locale values bound to wire:model on the
 *    component's regular public properties (e.g. $name, $description).
 * 2. **pendingTranslations** — an in-memory array keyed as [locale][field] => value,
 *    holding secondary-locale edits until the form is submitted.
 *
 * Livewire actions provided:
 * - switchLocale(string $locale) — swaps the active locale tab
 * - copyFromBaseline(string $field) — copies baseline value into the active locale
 *
 * @property string $activeLocale  Tracks which locale tab is currently displayed.
 * @property array  $pendingTranslations  [locale => [field => value]]
 */
trait BuildsTranslatableFormFields
{
    // ── Public Livewire Properties ─────────────────────
    // These MUST be declared on the component OR here as trait-level defaults.
    // Livewire reflects them automatically.

    public string $activeLocale = '';

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
        if ($this->activeLocale === $baselineLocale || !$this->activeLocale) {
            foreach ($translatableFields as $field) {
                if (property_exists($this, $field)) {
                    data_set($this, "pendingTranslations.{$baselineLocale}.{$field}", (string) $this->{$field});
                }
            }
        }

        $this->activeLocale = $locale;

        // If switching to baseline, restore baseline values to component properties
        if ($locale === $baselineLocale) {
            foreach ($translatableFields as $field) {
                $saved = data_get($this, "pendingTranslations.{$baselineLocale}.{$field}");
                if ($saved !== null && property_exists($this, $field)) {
                    $this->{$field} = $saved;
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
            ?? (property_exists($this, $field) ? (string) $this->{$field} : '');

        if ($this->activeLocale && $this->activeLocale !== $baselineLocale) {
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
        if (property_exists($this, 'language') && !empty($this->language)) {
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

        return array_values(array_filter(
            config('app.available_locales', ['en']),
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
        return \App\Enums\ContentLanguage::tryFrom($locale)?->label() ?? strtoupper($locale);
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

        if ($this->activeLocale === $baselineLocale || empty($this->activeLocale)) {
            return property_exists($this, $field) ? (string) $this->{$field} : '';
        }

        return data_get($this, "pendingTranslations.{$this->activeLocale}.{$field}", '');
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
     * @param  string[]  $fields  Translatable field names
     * @param  string  $baselineLocale  The model's primary locale
     * @return array<string, array<string, string>>
     */
    protected function buildTranslatableValues(array $fields, string $baselineLocale, array $input = []): array
    {
        $values = [];

        // Snapshot current baseline values into pendingTranslations so they're
        // available for secondary-locale reference (e.g. copyFromBaseline).
        foreach ($fields as $field) {
            $liveValue = $input[$field] ?? (property_exists($this, $field) ? (string) $this->{$field} : '');
            data_set($this, "pendingTranslations.{$baselineLocale}.{$field}", $liveValue);
        }

        foreach ($fields as $field) {
            // Baseline always comes from the live component property (wire:model writes here)
            $baselineValue = $input[$field] ?? (property_exists($this, $field) ? (string) $this->{$field} : '');
            $translations = [
                $baselineLocale => $baselineValue,
            ];

            foreach ($this->getSecondaryLocalesFor($baselineLocale) as $locale) {
                // Priority: pendingTranslations store → legacy _de property → input
                $value = data_get($this, "pendingTranslations.{$locale}.{$field}");
                if ($value === null || $value === '') {
                    $prop = "{$field}_{$locale}";
                    if (property_exists($this, $prop)) {
                        $value = (string) $this->{$prop};
                    } elseif (isset($input[$prop])) {
                        $value = $input[$prop];
                    }
                }
                if ($value !== null && $value !== '') {
                    $translations[$locale] = $value;
                }
            }
            $values[$field] = $translations;
        }
        return $values;
    }

    /**
     * Load translatable values from a model's stored translations.
     *
     * Populates pendingTranslations from the model. Also sets legacy _de
     * properties for backward compatibility if they exist on the component.
     */
    protected function loadTranslatableValues(Model $model, array $fields, ?string $overrideLocale = null): void
    {
        $primaryLocale = $overrideLocale ?? $model->language ?? app()->getLocale();

        // Initialize activeLocale
        if (empty($this->activeLocale)) {
            $this->activeLocale = $primaryLocale;
        }

        foreach ($fields as $field) {
            // Populate baseline in pendingTranslations
            $baselineValue = $model->getTranslation($field, $primaryLocale) ?? '';
            data_set($this, "pendingTranslations.{$primaryLocale}.{$field}", $baselineValue);

            // Set the component property for baseline
            if (property_exists($this, $field)) {
                $this->{$field} = $baselineValue;
            }

            // Populate secondary locales
            foreach ($this->getSecondaryLocalesFor($primaryLocale) as $locale) {
                $value = $model->getTranslation($field, $locale) ?? '';
                data_set($this, "pendingTranslations.{$locale}.{$field}", $value);

                // Legacy: also set _de properties for backward compatibility
                $prop = "{$field}_{$locale}";
                if (property_exists($this, $prop)) {
                    $this->{$prop} = $value;
                }
            }
        }
    }

    /**
     * Generate validation rules for secondary locale fields.
     * Supports both legacy _de properties and the new pendingTranslations store.
     *
     * @param  array<string, mixed>  $fieldRules  [field => baseRules]
     * @param  string  $primaryLanguage
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
            $secondaryRules = collect(is_array($baseRules) ? $baseRules : explode('|', $baseRules))
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
     * Get secondary locales for a specific baseline locale.
     *
     * @return string[]
     */
    protected function getSecondaryLocalesFor(string $baselineLocale): array
    {
        return array_values(array_filter(
            config('app.available_locales', ['en']),
            fn (string $locale) => $locale !== $baselineLocale,
        ));
    }
}
