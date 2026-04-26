<?php

use App\Exceptions\BggParseException;
use App\Services\BggXmlParser;

beforeEach(function () {
    $this->parser = new BggXmlParser;
});

describe('Happy path: Catan search fixture', function () {
    test('parses Catan search fixture with correct result count', function () {
        $xml = file_get_contents(__DIR__.'/../Fixtures/bgg-search-catan.xml');
        $results = $this->parser->parseSearchResults($xml);

        expect($results)->toHaveCount(5);
    });

    test('extracts base game Catan as first result', function () {
        $xml = file_get_contents(__DIR__.'/../Fixtures/bgg-search-catan.xml');
        $results = $this->parser->parseSearchResults($xml);
        $catan = $results[0];

        expect($catan['bgg_id'])->toBe(13);
        expect($catan['name'])->toBe('Catan');
        expect($catan['year_released'])->toBe(1995);
        expect($catan['bgg_type'])->toBe('boardgame');
    });

    test('extracts expansion with correct type', function () {
        $xml = file_get_contents(__DIR__.'/../Fixtures/bgg-search-catan.xml');
        $results = $this->parser->parseSearchResults($xml);
        $seafarers = $results[1];

        expect($seafarers['bgg_id'])->toBe(277);
        expect($seafarers['name'])->toBe('Catan: Seafarers');
        expect($seafarers['year_released'])->toBe(1996);
        expect($seafarers['bgg_type'])->toBe('boardgameexpansion');
    });

    test('handles ampersand entity in name correctly', function () {
        $xml = file_get_contents(__DIR__.'/../Fixtures/bgg-search-catan.xml');
        $results = $this->parser->parseSearchResults($xml);

        // "Catan: Cities & Knights" uses &amp; in XML
        expect($results[2]['name'])->toBe('Catan: Cities & Knights');
        expect($results[3]['name'])->toBe('Catan: Traders & Barbarians');
    });

    test('each result contains exactly the four expected keys', function () {
        $xml = file_get_contents(__DIR__.'/../Fixtures/bgg-search-catan.xml');
        $results = $this->parser->parseSearchResults($xml);

        $expectedKeys = ['bgg_id', 'name', 'year_released', 'bgg_type'];
        foreach ($results as $result) {
            expect(array_keys($result))->toBe($expectedKeys);
        }
    });
});

describe('Edge cases', function () {
    test('handles empty search results (total="0")', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<items total="0" termsofuse="https://boardgamegeek.com/xmlapi/termsofuse"/>
XML;

        $results = $this->parser->parseSearchResults($xml);
        expect($results)->toBe([]);
    });

    test('handles item with missing yearpublished as null', function () {
        $xml = <<<'XML'
<items total="1">
  <item type="boardgame" id="99999">
    <name type="primary" value="No Year Game"/>
  </item>
</items>
XML;

        $results = $this->parser->parseSearchResults($xml);
        expect($results)->toHaveCount(1);
        expect($results[0]['year_released'])->toBeNull();
        expect($results[0]['name'])->toBe('No Year Game');
    });

    test('handles item with empty yearpublished value as null', function () {
        $xml = <<<'XML'
<items total="1">
  <item type="boardgame" id="88888">
    <name type="primary" value="Empty Year"/>
    <yearpublished value=""/>
  </item>
</items>
XML;

        $results = $this->parser->parseSearchResults($xml);
        expect($results[0]['year_released'])->toBeNull();
    });

    test('falls back to first name when no primary name present', function () {
        $xml = <<<'XML'
<items total="1">
  <item type="boardgame" id="77777">
    <name type="alternate" value="Alt Name Only"/>
    <yearpublished value="2020"/>
  </item>
</items>
XML;

        $results = $this->parser->parseSearchResults($xml);
        expect($results[0]['name'])->toBe('Alt Name Only');
    });

    test('returns Unknown when no name elements present', function () {
        $xml = <<<'XML'
<items total="1">
  <item type="boardgame" id="66666"/>
</items>
XML;

        $results = $this->parser->parseSearchResults($xml);
        expect($results[0]['name'])->toBe('Unknown');
    });
});

describe('Negative tests', function () {
    test('throws BggParseException on malformed XML', function () {
        $thrown = false;
        try {
            $this->parser->parseSearchResults('<not valid xml <<<');
        } catch (BggParseException $e) {
            $thrown = true;
        }
        expect($thrown)->toBeTrue('Expected BggParseException to be thrown');
    });

    test('exception message contains descriptive context', function () {
        try {
            $this->parser->parseSearchResults('<not valid xml <<<');
            expect(true)->toBeFalse('Should have thrown');
        } catch (BggParseException $e) {
            expect($e->getMessage())->toContain('Failed to parse BGG XML');
            expect($e->getPrevious())->toBeInstanceOf(\Throwable::class);
        }
    });
});
