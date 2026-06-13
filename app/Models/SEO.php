<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
        /** @var Model&object{enableTitleSuffix: bool, created_at: ?Carbon, updated_at: ?Carbon} $model */
        $model = $this->model;

        $dynamic = null;

        if (method_exists($model, 'getDynamicSEOData')) {
            /** @var SEOData $dynamic */
            $dynamic = $model->getDynamicSEOData();
        }

        if (method_exists($model, 'enableTitleSuffix')) {
            $enableTitleSuffix = $model->enableTitleSuffix();
        } elseif (property_exists($model, 'enableTitleSuffix')) {
            $enableTitleSuffix = $model->enableTitleSuffix;
        }

        // Database (admin override) takes precedence over dynamic derivation.
        // $this->getAttributes()['field'] is used instead of $this->field to avoid
        // triggering Model::preventAccessingMissingAttributes() on nullable columns.
        $attrs = $this->getAttributes();
        $attr = fn (string $key) => is_string($attrs[$key] ?? null) ? $attrs[$key] : null;

        return new SEOData(
            title: $attr('title') ?? $dynamic->title ?? null,
            description: $attr('description') ?? $dynamic->description ?? null,
            author: $attr('author') ?? $dynamic->author ?? null,
            image: $attr('image') ?? $dynamic->image ?? null,
            url: $dynamic->url ?? null,
            enableTitleSuffix: $enableTitleSuffix ?? true,
            published_time: $dynamic->published_time ?? ($model->created_at ?? null),
            modified_time: $dynamic->modified_time ?? ($model->updated_at ?? null),
            articleBody: $dynamic->articleBody ?? null,
            section: $dynamic->section ?? null,
            tags: $dynamic->tags ?? null,
            schema: $dynamic->schema ?? null,
            type: $dynamic->type ?? null,
            locale: $dynamic->locale ?? null,
            robots: $attr('robots') ?? $dynamic->robots ?? null,
            canonical_url: $attr('canonical_url') ?? $dynamic->canonical_url ?? null,
            openGraphTitle: $dynamic->openGraphTitle ?? null,
            alternates: $dynamic->alternates ?? null,
        );
    }
}
