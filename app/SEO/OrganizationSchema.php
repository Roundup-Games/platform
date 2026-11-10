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

    /** @var Collection<string, mixed> */
    public Collection $data;

    public function initializeMarkup(SEOData $SEOData): void
    {
        $profiles = config('company.social_profiles');

        $this->data = collect([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => is_string($sn = config('seo.site_name', config('company.display_name'))) ? $sn : '',
            'url' => config('app.url'),
            'logo' => secure_url('icons/pwa-512x512.png'),
            'description' => $SEOData->description ?? ((is_string($dn = config('company.display_name')) ? $dn : '').' is a non-profit project — incorporating as a gGmbH in Berlin — helping communities organize and join local, in-person tabletop game sessions — board games, RPGs, and card games.'),
            'sameAs' => array_values(array_filter(
                is_array($profiles) ? $profiles : [],
                fn ($profile): bool => is_string($profile) && $profile !== ''
            )),
            'nonprofitStatus' => 'https://schema.org/NonprofitType/NonprofitOrganization',
            'areaServed' => ['@type' => 'City', 'name' => 'Berlin'],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => config('company.contact.support'),
            ],
        ]);
    }

    /**
     * @return Collection<int, mixed>
     */
    public function generateInner(): Collection
    {
        $result = $this->data->pipeThrough($this->markupTransformers);

        return $result instanceof Collection ? $result : collect();
    }
}
