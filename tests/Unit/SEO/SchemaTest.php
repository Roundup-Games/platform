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

    it('contains exactly 7 FAQ questions', function () {
        $schema = new AlgorithmsSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('mainEntity'))->toHaveCount(7);
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

    it('covers all 7 algorithm topics', function () {
        $schema = new AlgorithmsSchema(new SEOData);
        $data = $schema->generateInner();
        $names = $data->get('mainEntity')->pluck('name')->toArray();

        expect($names)->toContain('How does the Player Reliability Score work?');
        expect($names)->toContain('How are GM Ratings and Reviews calculated?');
        expect($names)->toContain('How does People Discovery suggest nearby players?');
        expect($names)->toContain('How are game sessions recommended to me?');
        expect($names)->toContain('How does the Proximity Engine find nearby sessions?');
        expect($names)->toContain('How are trending and popular sessions determined?');
        expect($names)->toContain('What is the Platform Score and how is it calculated?');
    });

    it('includes schema.org context', function () {
        $schema = new AlgorithmsSchema(new SEOData);
        $data = $schema->generateInner();

        expect($data->get('@context'))->toBe('https://schema.org');
    });
});
