<?php

use App\Exceptions\BggParseException;
use App\Services\BggXmlParser;

beforeEach(function () {
    $this->parser = new BggXmlParser;
});

// 1. Well-formed single-item XML — parse a fixture with name, description, year, min/max players, etc.
describe('Well-formed single-item XML', function () {
    test('parses Gloomhaven fixture extracting all fields correctly', function () {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/bgg-gloomhaven.xml');
        $items = $this->parser->parseItems($xml);

        expect($items)->toHaveCount(1);
        $data = $items[0];

        // Core identity
        expect($data['bgg_id'])->toBe(174430);
        expect($data['bgg_type'])->toBe('boardgame');
        expect($data['name'])->toBe('Gloomhaven');
        expect($data['description'])->toContain('Euro-inspired tactical combat');

        // Numeric fields
        expect($data['year_released'])->toBe(2017);
        expect($data['min_players'])->toBe(1);
        expect($data['max_players'])->toBe(4);
        expect($data['average_play_time'])->toBe(150);
        expect($data['age_rating'])->toBe(14);

        // URLs
        expect($data['thumbnail_url'])->toBe('https://cf.geekdo-images.com/thumb-thumb.jpg');
        expect($data['image_url'])->toBe('https://cf.geekdo-images.com/original-image.jpg');

        // Ratings
        expect($data['bgg_average_rating'])->toBe(8.71);
        expect($data['bgg_bayes_average'])->toBe(8.562);
        expect($data['bgg_users_rated'])->toBe(43210);
        expect($data['bgg_average_weight'])->toBe(3.86);
        expect($data['bgg_rank'])->toBe(1);

        // Taxonomy
        expect($data['categories'])->toContain('Adventure', 'Fantasy');
        expect($data['mechanics'])->toContain('Cooperative Game');
        expect($data['designers'])->toBe(['Isaac Childres']);
        expect($data['publishers'])->toContain('Cephalofair Games');
        expect($data['expansion_ids'])->toContain(246900, 256238);
        expect($data['base_game_bgg_id'])->toBeNull();
    });
});

// 2. Multi-item response
describe('Multi-item response', function () {
    test('parses XML with three items into array of three results', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="100">
    <name type="primary" value="Alpha"/>
    <description>First game.</description>
    <yearpublished value="2020"/>
  </item>
  <item type="boardgame" id="200">
    <name type="primary" value="Beta"/>
    <description>Second game.</description>
    <yearpublished value="2021"/>
  </item>
  <item type="boardgame" id="300">
    <name type="primary" value="Gamma"/>
    <description>Third game.</description>
    <yearpublished value="2022"/>
  </item>
</items>
XML;

        $items = $this->parser->parseItems($xml);

        expect($items)->toHaveCount(3);
        expect($items[0]['bgg_id'])->toBe(100);
        expect($items[0]['name'])->toBe('Alpha');
        expect($items[1]['bgg_id'])->toBe(200);
        expect($items[1]['name'])->toBe('Beta');
        expect($items[2]['bgg_id'])->toBe(300);
        expect($items[2]['name'])->toBe('Gamma');
    });
});

// 3. Missing optional fields
describe('Missing optional fields', function () {
    test('applies null defaults when optional fields absent', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="77777">
    <name type="primary" value="Sparse Game"/>
    <description>Minimal data.</description>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        expect($data['year_released'])->toBeNull();
        expect($data['min_players'])->toBeNull();
        expect($data['max_players'])->toBeNull();
        expect($data['average_play_time'])->toBeNull();
        expect($data['age_rating'])->toBeNull();
        expect($data['thumbnail_url'])->toBeNull();
        expect($data['image_url'])->toBeNull();
        expect($data['bgg_average_rating'])->toBeNull();
        expect($data['bgg_rank'])->toBeNull();
    });
});

// 4. Malformed XML
describe('Malformed XML', function () {
    test('throws BggParseException on garbage input', function () {
        $this->parser->parseItems('<<<not xml at all>>>');
    })->throws(BggParseException::class);

    test('exception preserves original parse error as previous', function () {
        try {
            $this->parser->parseItems('<<<not xml>>>');
            expect(true)->toBeFalse('Should have thrown');
        } catch (BggParseException $e) {
            expect($e->getMessage())->toContain('Failed to parse BGG XML');
            expect($e->getPrevious())->toBeInstanceOf(\Throwable::class);
        }
    });
});

// 5. Empty items list
describe('Empty items list', function () {
    test('returns empty array for items total zero', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<items total="0" termsofuse="https://boardgamegeek.com/xmlapi/termsofuse"/>
XML;

        $items = $this->parser->parseItems($xml);
        expect($items)->toBe([]);
    });

    test('returns empty array for items element with no children', function () {
        $xml = '<items></items>';
        $items = $this->parser->parseItems($xml);
        expect($items)->toBe([]);
    });
});

// 6. HTML error page — SimpleXML parses HTML as valid XML, so parseItems
//    returns empty array (no <item> children on <html> root)
describe('HTML error page', function () {
    test('returns empty array when HTML content passed instead of BGG XML', function () {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Error</title></head>
<body><h1>503 Service Unavailable</h1></body>
</html>
HTML;

        $items = $this->parser->parseItems($html);
        expect($items)->toBe([]);
    });
});

// 7. Special characters
describe('Special characters in name and description', function () {
    test('parses Ü ö é in name correctly', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="12345">
    <name type="primary" value="Grüße aus Köln"/>
    <description>Ein übermäßiger Café-Besuch für alle.</description>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        expect($data['name'])->toBe('Grüße aus Köln');
        expect($data['description'])->toContain('übermäßiger');
        expect($data['description'])->toContain('Café');
    });

    test('parses accented characters in link values', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="12346">
    <name type="primary" value="Jérémy's Game"/>
    <description>Test.</description>
    <link type="boardgamepublisher" id="500" value="Éditions Dupuis"/>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];
        expect($data['name'])->toBe("Jérémy's Game");
        expect($data['publishers'])->toContain('Éditions Dupuis');
    });
});

// 8. Large text fields
describe('Large text fields', function () {
    test('long description is preserved without truncation', function () {
        $longText = str_repeat('A', 10000);
        $xml = <<<XML
<items>
  <item type="boardgame" id="55555">
    <name type="primary" value="Verbose Game"/>
    <description>{$longText}</description>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        expect($data['description'])->toHaveLength(10000);
        expect($data['description'])->toBe($longText);
    });
});

// 9. Expansion with base game link
describe('Expansion with base game link', function () {
    test('extracts base_game_bgg_id from inbound expansion link', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgameexpansion" id="246900">
    <name type="primary" value="Gloomhaven: Forgotten Circles"/>
    <description>Expansion.</description>
    <yearpublished value="2019"/>
    <link type="boardgameexpansion" id="174430" value="Gloomhaven" inbound="true"/>
    <link type="boardgamecategory" id="1010" value="Fantasy"/>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        expect($data['bgg_type'])->toBe('boardgameexpansion');
        expect($data['base_game_bgg_id'])->toBe(174430);
        expect($data['expansion_ids'])->toBe([]);
    });

    test('outbound expansion links populate expansion_ids', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="174430">
    <name type="primary" value="Base Game"/>
    <description>Base.</description>
    <link type="boardgameexpansion" id="100" value="Expansion A"/>
    <link type="boardgameexpansion" id="200" value="Expansion B"/>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        expect($data['base_game_bgg_id'])->toBeNull();
        expect($data['expansion_ids'])->toBe([100, 200]);
    });
});

// 10. Link parsing — categories, mechanics, publishers, designers, families
describe('Link parsing', function () {
    test('extracts all link types with correct classification', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="99999">
    <name type="primary" value="Link Heavy Game"/>
    <description>Many links.</description>
    <link type="boardgamecategory" id="1020" value="Exploration"/>
    <link type="boardgamecategory" id="1010" value="Fantasy"/>
    <link type="boardgamemechanic" id="2001" value="Action Points"/>
    <link type="boardgamemechanic" id="2023" value="Cooperative Game"/>
    <link type="boardgamefamily" id="73592" value="Gloomhaven"/>
    <link type="boardgamedesigner" id="61388" value="Isaac Childres"/>
    <link type="boardgamepublisher" id="27989" value="Cephalofair Games"/>
    <link type="boardgamepublisher" id="34789" value="Asmodee Italia"/>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        expect($data['categories'])->toBe(['Exploration', 'Fantasy']);
        expect($data['mechanics'])->toBe(['Action Points', 'Cooperative Game']);
        expect($data['families'])->toBe(['Gloomhaven']);
        expect($data['designers'])->toBe(['Isaac Childres']);
        expect($data['publishers'])->toBe(['Cephalofair Games', 'Asmodee Italia']);
    });

    test('deduplicates taxonomy when same link appears multiple times', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="99998">
    <name type="primary" value="Dup Links"/>
    <description>Duplicates.</description>
    <link type="boardgamecategory" id="1010" value="Fantasy"/>
    <link type="boardgamecategory" id="1010" value="Fantasy"/>
    <link type="boardgamedesigner" id="100" value="Designer A"/>
    <link type="boardgamedesigner" id="100" value="Designer A"/>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        expect($data['categories'])->toBe(['Fantasy']);
        expect($data['designers'])->toBe(['Designer A']);
    });

    test('ignores unknown link types like boardgameartist', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="99997">
    <name type="primary" value="Artist Link Game"/>
    <description>Has artist link.</description>
    <link type="boardgameartist" id="1000" value="Some Artist"/>
    <link type="boardgamecategory" id="1010" value="Fantasy"/>
  </item>
</items>
XML;

        $data = $this->parser->parseItems($xml)[0];

        // Artist links are not extracted into any taxonomy array
        expect($data['categories'])->toBe(['Fantasy']);
        expect($data['designers'])->toBe([]);
        expect($data['publishers'])->toBe([]);
    });
});

// 11. Additional edge cases from root fixture audit
describe('Additional edge cases', function () {
    test('preserves HTML in description', function () {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/bgg-gloomhaven.xml');
        $data = $this->parser->parseItems($xml)[0];
        expect($data['description'])->toContain('<br/>'); // HTML preserved
    });

    test('Gloomhaven fixture taxonomy includes all expected values', function () {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/bgg-gloomhaven.xml');
        $data = $this->parser->parseItems($xml)[0];

        expect($data['categories'])->toContain('Adventure', 'Exploration', 'Fantasy', 'Fighting');
        expect($data['mechanics'])->toContain('Action Points', 'Cooperative Game', 'Variable Player Powers', 'Storytelling');
        expect($data['families'])->toContain('Gloomhaven', 'Components: Miniatures', 'Mechanism: Legacy');
        expect($data['publishers'])->toContain('Cephalofair Games', 'Asmodee Italia');
    });

    test('handles empty description element', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="11111">
    <name type="primary" value="No Desc Game"/>
    <description></description>
  </item>
</items>
XML;
        expect($this->parser->parseItems($xml)[0]['description'])->toBe('');
    });

    test('handles missing description element', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="11112">
    <name type="primary" value="No Desc Element"/>
  </item>
</items>
XML;
        expect($this->parser->parseItems($xml)[0]['description'])->toBe('');
    });

    test('falls back to first name when no primary name', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="33333">
    <name type="alternate" value="Alt Name Game"/>
    <description>Test.</description>
  </item>
</items>
XML;
        expect($this->parser->parseItems($xml)[0]['name'])->toBe('Alt Name Game');
    });

    test('returns Unknown when no name elements present', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="44444">
    <description>No name.</description>
  </item>
</items>
XML;
        expect($this->parser->parseItems($xml)[0]['name'])->toBe('Unknown');
    });

    test('handles unranked game with rank value "Not Ranked"', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="55555">
    <name type="primary" value="Unranked Game"/>
    <description>No rank.</description>
    <statistics>
      <ratings>
        <usersrated value="10"/>
        <average value="5.5"/>
        <bayesaverage value="0"/>
        <ranks>
          <rank type="subtype" id="1" name="boardgame" value="Not Ranked" bayesaverage="0"/>
        </ranks>
        <averageweight value="2.0"/>
      </ratings>
    </statistics>
  </item>
</items>
XML;
        expect($this->parser->parseItems($xml)[0]['bgg_rank'])->toBeNull();
    });

    test('handles unranked game with empty rank value', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="55556">
    <name type="primary" value="Empty Rank Game"/>
    <description>No rank.</description>
    <statistics>
      <ratings>
        <usersrated value="5"/>
        <average value="4.0"/>
        <bayesaverage value="0"/>
        <ranks>
          <rank type="subtype" id="1" name="boardgame" value="" bayesaverage="0"/>
        </ranks>
        <averageweight value="1.5"/>
      </ratings>
    </statistics>
  </item>
</items>
XML;
        expect($this->parser->parseItems($xml)[0]['bgg_rank'])->toBeNull();
    });

    test('handles self-closing image element with null image_url', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="77778">
    <name type="primary" value="No Image Game"/>
    <description>Has thumbnail but no image.</description>
    <thumbnail>https://cf.geekdo-images.com/thumb.jpg</thumbnail>
    <image/>
  </item>
</items>
XML;
        $data = $this->parser->parseItems($xml)[0];
        expect($data['thumbnail_url'])->toBe('https://cf.geekdo-images.com/thumb.jpg');
        expect($data['image_url'])->toBeNull();
    });

    test('handles special characters in taxonomy via XML entities', function () {
        $xml = <<<'XML'
<items>
  <item type="boardgame" id="22222">
    <name type="primary" value="Special Chars Game"/>
    <description>Test.</description>
    <link type="boardgamecategory" id="100" value="Sci-Fi &amp; Fantasy"/>
    <link type="boardgamemechanic" id="200" value="Worker Placement &amp; Drafting"/>
  </item>
</items>
XML;
        $data = $this->parser->parseItems($xml)[0];
        expect($data['categories'])->toContain('Sci-Fi & Fantasy');
        expect($data['mechanics'])->toContain('Worker Placement & Drafting');
    });
});
