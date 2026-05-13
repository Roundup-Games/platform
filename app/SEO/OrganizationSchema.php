<?php

namespace App\SEO;

use Illuminate\Support\Collection;
use RalphJSmit\Laravel\SEO\Schema\CustomSchemaFluent;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Organization JSON-LD schema for the homepage.
 *
 * Provides structured data identifying Roundup Games as an organization,
 * helping search engines and LLMs confidently answer brand/company queries.
 */
class OrganizationSchema extends CustomSchemaFluent
{
    public string $type = 'Organization';

    public Collection $data;

    public function initializeMarkup(SEOData $SEOData): void
    {
        $this->data = collect([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => config('seo.site_name', 'Roundup Games'),
            'url' => config('app.url'),
            'logo' => secure_url('icons/pwa-512x512.png'),
            'description' => $SEOData->description ?? config('seo.description.fallback', ''),
            'sameAs' => [],
        ]);
    }

    public function generateInner(): Collection
    {
        return $this->data->pipeThrough($this->markupTransformers);
    }
}
