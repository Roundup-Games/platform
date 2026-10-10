<?php

namespace App\Models\Concerns;

use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Entity SEO resolution authority (D172): per-locale curated overrides
 * over the model's generated metadata.
 *
 * Storage contract: translatable jsonb columns `seo_title` and
 * `seo_description` (add the column names to the model's $translatable
 * array; HasTranslations is required). An absent or whitespace-only
 * locale value means "no override" — never leak another locale's curated
 * text; the model's generated output serves instead.
 *
 * Resolution: entitySeoData($locale) starts from the model's existing
 * getDynamicSEOData() generator (title/description follow the content
 * locale, and images, robots, and JSON-LD schema stay generator-owned —
 * no per-entity image/canonical/robots curation; the seo-table override
 * layer's never-used fields are intentionally not replaced) and layers
 * the curated title/description for the active locale on top.
 *
 * Render call sites use seo()->for($model->entitySeoData(app()->getLocale()))
 * — never seo()->for($model), which reads the retired seo-table row layer
 * (INV-13). City hubs implement the same standard through CitySummary
 * (reference: 2026_10_09_170000).
 */
trait HasEntitySeo
{
    /**
     * Curated SEO title for one locale: trimmed value, or null when the
     * locale has no curated override.
     */
    public function seoTitleFor(string $locale): ?string
    {
        return $this->localizedSeoText($this->getTranslations('seo_title'), $locale);
    }

    /**
     * Curated meta description for one locale (same null-when-absent
     * semantics as seoTitleFor).
     */
    public function seoDescriptionFor(string $locale): ?string
    {
        return $this->localizedSeoText($this->getTranslations('seo_description'), $locale);
    }

    /**
     * Full SEOData for one locale: generated base, curated overrides on
     * top. The global SEODataTransformer adds canonical + hreflang
     * alternates downstream, exactly as before.
     */
    public function entitySeoData(string $locale): SEOData
    {
        $seoData = $this->getDynamicSEOData();

        $title = $this->seoTitleFor($locale);
        if ($title !== null) {
            $seoData->title = $title;
        }

        $description = $this->seoDescriptionFor($locale);
        if ($description !== null) {
            $seoData->description = $description;
        }

        return $seoData;
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function localizedSeoText(array $map, string $locale): ?string
    {
        $text = $map[$locale] ?? null;

        if (! is_string($text)) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
    }
}
