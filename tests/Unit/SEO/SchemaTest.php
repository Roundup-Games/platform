<?php

use App\SEO\AlgorithmsSchema;
use App\SEO\OrganizationSchema;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('OrganizationSchema', function () {
    it('includes nonprofitStatus field', function () {
        $schema = new OrganizationSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('nonprofitStatus'))
            ->toBe('https://schema.org/NonprofitType/NonprofitOrganization');
    });

    it('includes GitHub in sameAs', function () {
        $schema = new OrganizationSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('sameAs'))->toContain('https://github.com/Roundup-Games/');
    });

    it('includes areaServed', function () {
        $schema = new OrganizationSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('areaServed'))->toBe('Worldwide');
    });

    it('uses non-profit description as fallback when no SEOData description', function () {
        $schema = new OrganizationSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('description'))->toContain('non-profit');
        expect($data->get('description'))->toContain('in-person');
    });

    it('uses SEOData description when provided', function () {
        $schema = new OrganizationSchema(new SEOData(description: 'Custom description'));
        $data = $schema->generateInner();

        expect($data->get('description'))->toBe('Custom description');
    });

    it('sets correct schema type', function () {
        $schema = new OrganizationSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('@type'))->toBe('Organization');
    });
});

describe('AlgorithmsSchema', function () {
    it('sets FAQPage schema type', function () {
        $schema = new AlgorithmsSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('@type'))->toBe('FAQPage');
    });

    it('exposes a non-empty set of distinct FAQ questions', function () {
        $schema = new AlgorithmsSchema(new SEOData);
        $data = $schema->generateInner();
        $names = $data->get('mainEntity')->pluck('name')->toArray();

        // Count is intentionally not pinned: a legitimate content edit (add/
        // remove a question) should not break the suite. What matters is that
        // the questions are present and distinct (no accidental duplicates).
        expect($names)->not->toBeEmpty()
            ->and(count($names))->toBe(count(array_unique($names)));
    });

    it('each question has required Question/Answer structure', function () {
        $schema = new AlgorithmsSchema(new SEOData);
        $data = $schema->generateInner();

        foreach ($data->get('mainEntity') as $question) {
            expect($question)->toHaveKey('@type', 'Question');
            expect($question)->toHaveKey('name');
            expect($question)->toHaveKey('acceptedAnswer');
            expect($question['acceptedAnswer'])->toHaveKey('@type', 'Answer');
            expect($question['acceptedAnswer'])->toHaveKey('text');
            expect(strlen($question['name']))->toBeGreaterThan(0);
            expect(strlen($question['acceptedAnswer']['text']))->toBeGreaterThan(20);
        }
    });

    it('includes schema.org context', function () {
        $schema = new AlgorithmsSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('@context'))->toBe('https://schema.org');
    });
});
