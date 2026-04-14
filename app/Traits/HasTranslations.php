<?php

namespace App\Traits;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasTranslations
{
    /**
     * Get the translatable fields for this model.
     * Override by defining $translatable on the model class.
     */
    public function getTranslatableFields(): array
    {
        return property_exists($this, 'translatable') && isset($this->translatable)
            ? $this->translatable
            : [];
    }

    /**
     * Polymorphic relationship to translations.
     */
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /**
     * Get a translated value for a specific locale and field.
     *
     * For fields cast as 'array' in the model, the value is JSON-decoded.
     * Falls back to the entity's own attribute when no translation row exists
     * and the requested locale matches the app's current locale.
     */
    public function getTranslation(string $locale, string $field): mixed
    {
        if ($this->relationLoaded('translations')) {
            $translation = $this->translations->first(
                fn ($t) => $t->locale === $locale && $t->field === $field
            );
        } else {
            $translation = $this->translations()
                ->where('locale', $locale)
                ->where('field', $field)
                ->first();
        }

        if ($translation !== null) {
            return $this->decodeFieldValue($field, $translation->value);
        }

        // Fallback: if requesting the app's current locale, return the entity's own attribute
        if ($locale === app()->getLocale()) {
            return $this->getAttribute($field);
        }

        return null;
    }

    /**
     * Set a translation for a specific locale and field.
     *
     * For fields cast as 'array' in the model, the value is JSON-encoded before storage.
     * Returns $this for chaining.
     */
    public function setTranslation(string $locale, string $field, mixed $value): static
    {
        $storedValue = $this->encodeFieldValue($field, $value);

        $this->translations()->updateOrCreate(
            ['locale' => $locale, 'field' => $field],
            ['value' => $storedValue],
        );

        return $this;
    }

    /**
     * Get all translations for a given locale as an associative array [field => value].
     *
     * Falls back to the entity's own attribute for each translatable field
     * that has no translation row in the given locale.
     */
    public function getTranslationsForLocale(string $locale): array
    {
        if ($this->relationLoaded('translations')) {
            $translations = $this->translations
                ->where('locale', $locale)
                ->mapWithKeys(fn ($t) => [$t->field => $this->decodeFieldValue($t->field, $t->value)]);
        } else {
            $translations = $this->translations()
                ->where('locale', $locale)
                ->get()
                ->mapWithKeys(fn ($t) => [$t->field => $this->decodeFieldValue($t->field, $t->value)]);
        }

        $result = [];
        foreach ($this->getTranslatableFields() as $field) {
            $result[$field] = $translations->has($field)
                ? $translations[$field]
                : $this->getAttribute($field);
        }

        return $result;
    }

    /**
     * Placeholder for future caching integration.
     */
    public function forgetTranslationsCache(): void
    {
        // No-op for now; will be extended when caching is added.
    }

    /**
     * Determine if a field is cast as 'array' in the model.
     */
    protected function isFieldArrayCast(string $field): bool
    {
        $casts = $this->getCasts();

        return isset($casts[$field]) && $casts[$field] === 'array';
    }

    /**
     * Encode a value for storage, JSON-encoding array-cast fields.
     */
    protected function encodeFieldValue(string $field, mixed $value): ?string
    {
        if ($this->isFieldArrayCast($field) && (is_array($value) || is_object($value))) {
            return json_encode($value);
        }

        return $value;
    }

    /**
     * Decode a value from storage, JSON-decoding array-cast fields.
     */
    protected function decodeFieldValue(string $field, ?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->isFieldArrayCast($field)) {
            return json_decode($value, true);
        }

        return $value;
    }
}
