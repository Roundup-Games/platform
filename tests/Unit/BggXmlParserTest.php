<?php

use App\Exceptions\BggParseException;
use App\Services\BggXmlParser;

beforeEach(function () {
    $this->parser = new BggXmlParser;
});

describe('Happy path: Gloomhaven fixture', function () {
    test('parses Gloomhaven fixture correctly with all fields populated', function () {
        $xml = file_get_contents(__DIR__.'/../Fixtures/bgg-gloomhaven.xml');
        $items = $this->parser->parseItems($xml);

        expect($items)->toHaveCount(1);

        $data = $items[0];

        // Core identity
        expect($data['bgg_id'])->toBe(174430);
        expect($data['bgg_type'])->toBe('boardgame');
        expect($data['name'])->toBe('Gloomhaven');
        expect($data['description'])->toContain('Euro-inspired tactical combat');
        expect($data['description'])->toContain('<br/>'); // HTML preserved

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
        expect($data['categories'])->toContain('Adventure', 'Exploration', 'Fantasy', 'Fighting');
        expect($data['mechanics'])->toContain('Action Points', 'Cooperative Game', 'Variable Player Powers', 'Storytelling');
        expect($data['families'])->toContain('Gloomhaven', 'Components: Miniatures', 'Mechanism: Legacy');
        expect($data['designers'])->toBe(['Isaac Childres']);
        expect($data['publishers'])->toContain('Cephalofair Games', 'Asmodee Italia');

        // Expansions (outbound)
        expect($data['expansion_ids'])->toContain(246900, 256238);
        expect($data['base_game_bgg_id'])->toBeNull(); // Gloomhaven is a base game, not an expansion
    });
});

describe('Edge cases', function () {
    test('handles missing statistics gracefully with all stat fields null', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="99999">
        <name type="primary" value="No Stats Game"/>
        <description>A game without stats.</description>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        $data = $items[0];

        expect($data['bgg_average_rating'])->toBeNull();
        expect($data['bgg_bayes_average'])->toBeNull();
        expect($data['bgg_users_rated'])->toBeNull();
        expect($data['bgg_average_weight'])->toBeNull();
        expect($data['bgg_rank'])->toBeNull();
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

        $items = $this->parser->parseItems($xml);
        expect($items[0]['bgg_rank'])->toBeNull();
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

        $items = $this->parser->parseItems($xml);
        expect($items[0]['bgg_rank'])->toBeNull();
    });

    test('handles expansion type item with base_game_bgg_id from inbound link', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgameexpansion" id="246900">
        <name type="primary" value="Gloomhaven: Forgotten Circles"/>
        <description>An expansion for Gloomhaven.</description>
        <yearpublished value="2019"/>
        <link type="boardgameexpansion" id="174430" value="Gloomhaven" inbound="true"/>
        <link type="boardgamecategory" id="1010" value="Fantasy"/>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        $data = $items[0];

        expect($data['bgg_type'])->toBe('boardgameexpansion');
        expect($data['base_game_bgg_id'])->toBe(174430);
        expect($data['expansion_ids'])->toBe([]);
    });

    test('handles empty description', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="11111">
        <name type="primary" value="No Desc Game"/>
        <description></description>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        expect($items[0]['description'])->toBe('');
    });

    test('handles missing description element', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="11112">
        <name type="primary" value="No Desc Element"/>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        expect($items[0]['description'])->toBe('');
    });

    test('handles special characters in taxonomy names', function () {
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

        $items = $this->parser->parseItems($xml);
        expect($items[0]['categories'])->toContain('Sci-Fi & Fantasy');
        expect($items[0]['mechanics'])->toContain('Worker Placement & Drafting');
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

        $items = $this->parser->parseItems($xml);
        expect($items[0]['name'])->toBe('Alt Name Game');
    });

    test('returns Unknown when no name elements present', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="44444">
        <description>No name.</description>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        expect($items[0]['name'])->toBe('Unknown');
    });

    test('parseItems handles multiple items', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="111">
        <name type="primary" value="Game A"/>
        <description>Description A.</description>
      </item>
      <item type="boardgame" id="222">
        <name type="primary" value="Game B"/>
        <description>Description B.</description>
      </item>
      <item type="boardgame" id="333">
        <name type="primary" value="Game C"/>
        <description>Description C.</description>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        expect($items)->toHaveCount(3);
        expect($items[0]['bgg_id'])->toBe(111);
        expect($items[0]['name'])->toBe('Game A');
        expect($items[1]['bgg_id'])->toBe(222);
        expect($items[1]['name'])->toBe('Game B');
        expect($items[2]['bgg_id'])->toBe(333);
        expect($items[2]['name'])->toBe('Game C');
    });

    test('handles item with zero links producing empty taxonomy arrays', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="88888">
        <name type="primary" value="Lonely Game"/>
        <description>No links at all.</description>
        <yearpublished value="2020"/>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        $data = $items[0];

        expect($data['categories'])->toBe([]);
        expect($data['mechanics'])->toBe([]);
        expect($data['families'])->toBe([]);
        expect($data['designers'])->toBe([]);
        expect($data['publishers'])->toBe([]);
        expect($data['expansion_ids'])->toBe([]);
        expect($data['base_game_bgg_id'])->toBeNull();
    });

    test('deduplicates taxonomy names from duplicate link elements', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="99999">
        <name type="primary" value="Dup Game"/>
        <description>Dupes.</description>
        <link type="boardgamecategory" id="1010" value="Fantasy"/>
        <link type="boardgamecategory" id="1010" value="Fantasy"/>
        <link type="boardgamecategory" id="1020" value="Exploration"/>
        <link type="boardgamedesigner" id="100" value="Designer A"/>
        <link type="boardgamedesigner" id="100" value="Designer A"/>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        $data = $items[0];

        expect($data['categories'])->toBe(['Fantasy', 'Exploration']);
        expect($data['designers'])->toBe(['Designer A']);
    });

    test('handles missing optional fields with null values', function () {
        $xml = <<<'XML'
    <items>
      <item type="boardgame" id="77777">
        <name type="primary" value="Sparse Game"/>
        <description>Minimal data.</description>
      </item>
    </items>
    XML;

        $items = $this->parser->parseItems($xml);
        $data = $items[0];

        expect($data['year_released'])->toBeNull();
        expect($data['min_players'])->toBeNull();
        expect($data['max_players'])->toBeNull();
        expect($data['average_play_time'])->toBeNull();
        expect($data['age_rating'])->toBeNull();
        expect($data['thumbnail_url'])->toBeNull();
        expect($data['image_url'])->toBeNull();
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

        $items = $this->parser->parseItems($xml);
        $data = $items[0];

        expect($data['thumbnail_url'])->toBe('https://cf.geekdo-images.com/thumb.jpg');
        expect($data['image_url'])->toBeNull();
    });
});

describe('Negative tests', function () {
    test('throws BggParseException on malformed XML', function () {
        $thrown = false;
        try {
            $this->parser->parseItems('<not valid xml <<<');
        } catch (BggParseException $e) {
            $thrown = true;
        }
        expect($thrown)->toBeTrue('Expected BggParseException to be thrown');
    });

    test('exception message contains descriptive context', function () {
        try {
            $this->parser->parseItems('<not valid xml <<<');
            expect(true)->toBeFalse('Should have thrown');
        } catch (BggParseException $e) {
            expect($e->getMessage())->toContain('Failed to parse BGG XML');
            expect($e->getPrevious())->toBeInstanceOf(\Throwable::class);
        }
    });
});
