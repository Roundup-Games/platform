<?php

namespace App\Models;

use RalphJSmit\Laravel\SEO\Models\SEO as BaseSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Custom SEO model that inverts the override precedence.
 *
 * The base vendor model uses dynamic data first and falls back to database values.
 * This override swaps that: admin database values take precedence over dynamically
 * derived data. When an admin sets an override via Filament, it appears on the
 * public page immediately (after cache clear). Leaving a field blank lets the
 * dynamic derivation provide the value.
 */
class SEO extends BaseSEO
{
    public function prepareForUsage(): SEOData
    {
        $dynamic = null;

        if (method_exists($this->model, 'getDynamicSEOData')) {
            /** @var SEOData $dynamic */
            $dynamic = $this->model->getDynamicSEOData();
        }

        if (method_exists($this->model, 'enableTitleSuffix')) {
            $enableTitleSuffix = $this->model->enableTitleSuffix();
        } elseif (property_exists($this->model, 'enableTitleSuffix')) {
            $enableTitleSuffix = $this->model->enableTitleSuffix;
        }

        // Database (admin override) takes precedence over dynamic derivation.
        // $this->getAttributes()['field'] is used instead of $this->field to avoid
        // triggering Model::preventAccessingMissingAttributes() on nullable columns.
        return new SEOData(
            title: $this->getAttributes()['title'] ?? $dynamic->title ?? null,
            description: $this->getAttributes()['description'] ?? $dynamic->description ?? null,
            author: $this->getAttributes()['author'] ?? $dynamic->author ?? null,
            image: $this->getAttributes()['image'] ?? $dynamic->image ?? null,
            url: $dynamic->url ?? null,
            enableTitleSuffix: $enableTitleSuffix ?? true,
            published_time: $dynamic->published_time ?? ($this->model?->created_at ?? null),
            modified_time: $dynamic->modified_time ?? ($this->model?->updated_at ?? null),
            articleBody: $dynamic->articleBody ?? null,
            section: $dynamic->section ?? null,
            tags: $dynamic->tags ?? null,
            schema: $dynamic->schema ?? null,
            type: $dynamic->type ?? null,
            locale: $dynamic->locale ?? null,
            robots: $this->getAttributes()['robots'] ?? $dynamic->robots ?? null,
            canonical_url: $this->getAttributes()['canonical_url'] ?? $dynamic->canonical_url ?? null,
            openGraphTitle: $dynamic->openGraphTitle ?? null,
            alternates: $dynamic->alternates ?? null,
        );
    }
}
