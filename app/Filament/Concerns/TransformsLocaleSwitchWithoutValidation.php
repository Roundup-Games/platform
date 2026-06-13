<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Arr;

/**
 * Override the lara-zeus/spatie-translatable locale switch behavior to skip
 * validation when capturing the current form state. The parent trait calls
 * `$this->form->getState()` which triggers validation — but locale switching
 * is a navigation action, not a save, so required-field validation should not
 * block it.
 *
 * Use this trait AFTER the Translatable trait in edit pages that need it.
 *
 * See Filament docs — loaded by resource pages via OverridesAttendance::class
 *
 * @phpstan-ignore trait.unused
 */
trait TransformsLocaleSwitchWithoutValidation
{
    public function updatedActiveLocale(): void
    {
        if (filament('spatie-translatable')?->getPersistLocale()) {
            session()->put('spatie_translatable_active_locale', $this->activeLocale);
        }

        if (blank($this->oldActiveLocale)) {
            return;
        }

        $this->resetValidation();

        $translatableAttributes = static::getResource()::getTranslatableAttributes();

        // Use getRawState() instead of getState() to skip validation.
        // The locale switch is a navigation action, not a save.
        $rawState = $this->form->getRawState();

        $this->otherLocaleData[$this->oldActiveLocale] = Arr::only(
            $rawState,
            $translatableAttributes,
        );

        $this->form->fill([
            ...Arr::except(
                $rawState,
                $translatableAttributes,
            ),
            ...$this->otherLocaleData[$this->activeLocale] ?? [],
        ]);

        unset($this->otherLocaleData[$this->activeLocale]);
    }
}
