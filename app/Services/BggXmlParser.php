<?php

namespace App\Services;

use App\Exceptions\BggParseException;
use SimpleXMLElement;

class BggXmlParser
{
    /**
     * Parse a BGG search results XML document into a lightweight array of results.
     *
     * Each result contains: bgg_id (int), name (string), year_released (int|null), bgg_type (string).
     *
     * @return array<int, array{bgg_id: int, name: string, year_released: int|null, bgg_type: string}>
     *
     * @throws BggParseException
     */
    public function parseSearchResults(string $xmlString): array
    {
        $prevInternalErrors = libxml_use_internal_errors(true);

        try {
            $items = new SimpleXMLElement($xmlString);
            libxml_clear_errors();
        } catch (\Throwable $e) {
            throw BggParseException::fromXmlError($e);
        } finally {
            libxml_use_internal_errors($prevInternalErrors);
        }

        $result = [];
        foreach ($items->item as $item) {
            $result[] = [
                'bgg_id' => (int) $item['id'],
                'name' => $this->extractSearchName($item),
                'year_released' => $this->nullableInt($item->yearpublished['value'] ?? null),
                'bgg_type' => (string) $item['type'],
            ];
        }

        return $result;
    }

    /**
     * Extract the primary name from a search result item element.
     */
    private function extractSearchName(SimpleXMLElement $item): string
    {
        foreach ($item->name as $name) {
            if ((string) $name['type'] === 'primary') {
                $value = (string) $name['value'];

                return $value !== '' ? $value : 'Unknown';
            }
        }

        if ($item->name instanceof SimpleXMLElement) {
            $value = (string) $item->name['value'];

            return $value !== '' ? $value : 'Unknown';
        }

        return 'Unknown';
    }

    /**
     * Parse a full `<items>` XML document into an array of parsed item arrays.
     *
     * @return array<int, array>
     * @return list<array<string, mixed>>
     *
     * @throws BggParseException
     */
    public function parseItems(string $xmlString): array
    {
        $prevInternalErrors = libxml_use_internal_errors(true);

        try {
            $items = new SimpleXMLElement($xmlString);
            libxml_clear_errors();
        } catch (\Throwable $e) {
            throw BggParseException::fromXmlError($e);
        } finally {
            libxml_use_internal_errors($prevInternalErrors);
        }

        $result = [];
        foreach ($items->item as $item) {
            $result[] = $this->parseItem($item);
        }

        return $result;
    }

    /**
     * Parse a single `<item>` SimpleXMLElement into a structured array.
     *
     * @return array<string, mixed>
     */
    public function parseItem(SimpleXMLElement $item): array
    {
        $linkData = $this->extractLinks($item);
        $stats = $this->extractStatistics($item);

        return [
            'bgg_id' => (int) $item['id'],
            'bgg_type' => (string) $item['type'],
            'name' => $this->extractName($item),
            'description' => $this->extractDescription($item),
            'year_released' => $this->nullableInt($item->yearpublished['value'] ?? null),
            'min_players' => $this->nullableInt($item->minplayers['value'] ?? null),
            'max_players' => $this->nullableInt($item->maxplayers['value'] ?? null),
            'average_play_time' => $this->nullableInt($item->maxplaytime['value'] ?? null),
            'age_rating' => $this->nullableInt($item->minage['value'] ?? null),
            'thumbnail_url' => $this->nullableString($item->thumbnail ?? null),
            'image_url' => $this->nullableString($item->image ?? null),
            'bgg_average_rating' => $stats['average'],
            'bgg_bayes_average' => $stats['bayesaverage'],
            'bgg_users_rated' => $stats['usersrated'],
            'bgg_average_weight' => $stats['averageweight'],
            'bgg_rank' => $stats['rank'],
            'categories' => $linkData['categories'],
            'mechanics' => $linkData['mechanics'],
            'families' => $linkData['families'],
            'designers' => $linkData['designers'],
            'publishers' => $linkData['publishers'],
            'expansion_ids' => $linkData['expansion_ids'],
            'base_game_bgg_id' => $linkData['base_game_bgg_id'],
        ];
    }

    /**
     * Extract the primary name from an item element.
     */
    private function extractName(SimpleXMLElement $item): string
    {
        // Prefer name with type="primary"
        foreach ($item->name as $name) {
            if ((string) $name['type'] === 'primary') {
                $value = (string) $name['value'];

                return $value !== '' ? $value : 'Unknown';
            }
        }

        // Fall back to first <name> element
        if ($item->name instanceof SimpleXMLElement) {
            $value = (string) $item->name['value'];

            return $value !== '' ? $value : 'Unknown';
        }

        return 'Unknown';
    }

    /**
     * Extract the description, returning empty string if absent.
     */
    private function extractDescription(SimpleXMLElement $item): string
    {
        $desc = $item->description ?? null;

        if ($desc === null) {
            return '';
        }

        return (string) $desc;
    }

    /**
     * Extract all link-type taxonomy data from the item.
     *
     * @return array<string, mixed>
     */
    private function extractLinks(SimpleXMLElement $item): array
    {
        $categories = [];
        $mechanics = [];
        $families = [];
        $designers = [];
        $publishers = [];
        $expansion_ids = [];
        $base_game_bgg_id = null;

        foreach ($item->link as $link) {
            $type = (string) $link['type'];
            $value = (string) $link['value'];
            $id = (int) $link['id'];
            $inbound = isset($link['inbound']) && (string) $link['inbound'] === 'true';

            switch ($type) {
                case 'boardgamecategory':
                    $categories[] = $value;
                    break;
                case 'boardgamemechanic':
                    $mechanics[] = $value;
                    break;
                case 'boardgamefamily':
                    $families[] = $value;
                    break;
                case 'boardgamedesigner':
                    $designers[] = $value;
                    break;
                case 'boardgamepublisher':
                    $publishers[] = $value;
                    break;
                case 'boardgameexpansion':
                    if ($inbound) {
                        // Inbound: the linked game is the base game this item expands
                        $base_game_bgg_id = $id;
                    } else {
                        // Outbound: this game has the linked game as an expansion
                        $expansion_ids[] = $id;
                    }
                    break;
            }
        }

        // Deduplicate taxonomy arrays
        return [
            'categories' => array_values(array_unique($categories)),
            'mechanics' => array_values(array_unique($mechanics)),
            'families' => array_values(array_unique($families)),
            'designers' => array_values(array_unique($designers)),
            'publishers' => array_values(array_unique($publishers)),
            'expansion_ids' => array_values(array_unique($expansion_ids)),
            'base_game_bgg_id' => $base_game_bgg_id,
        ];
    }

    /**
     * Extract statistics/ratings from the item.
     *
     * @return array<string, mixed>
     */
    private function extractStatistics(SimpleXMLElement $item): array
    {
        $ratings = $item->statistics->ratings ?? null;

        if ($ratings === null) {
            return [
                'average' => null,
                'bayesaverage' => null,
                'usersrated' => null,
                'averageweight' => null,
                'rank' => null,
            ];
        }

        return [
            'average' => $this->nullableFloat($ratings->average['value'] ?? null),
            'bayesaverage' => $this->nullableFloat($ratings->bayesaverage['value'] ?? null),
            'usersrated' => $this->nullableInt($ratings->usersrated['value'] ?? null),
            'averageweight' => $this->nullableFloat($ratings->averageweight['value'] ?? null),
            'rank' => $this->extractRank($ratings),
        ];
    }

    /**
     * Extract the boardgame rank (subtype) from the ratings.
     */
    private function extractRank(SimpleXMLElement $ratings): ?int
    {
        $ranks = $ratings->ranks ?? null;

        if ($ranks === null) {
            return null;
        }

        foreach ($ranks->rank as $rank) {
            if ((string) $rank['type'] === 'subtype') {
                $value = (string) $rank['value'];

                if ($value === '' || $value === 'Not Ranked') {
                    return null;
                }

                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Cast an XML attribute value to int, or null if missing/empty.
     */
    private function nullableInt(SimpleXMLElement|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        if ($string === '') {
            return null;
        }

        return (int) $string;
    }

    /**
     * Cast an XML attribute value to float, or null if missing/empty.
     */
    private function nullableFloat(SimpleXMLElement|string|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        if ($string === '') {
            return null;
        }

        return (float) $string;
    }

    /**
     * Return a string value, or null if the element is absent.
     */
    private function nullableString(SimpleXMLElement|string|null $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $string = (string) $element;

        return $string === '' ? null : $string;
    }
}
