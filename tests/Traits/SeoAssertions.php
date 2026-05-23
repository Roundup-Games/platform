<?php

namespace Tests\Traits;

use Illuminate\Testing\TestResponse;

/**
 * SEO assertion helpers for test classes.
 * Provides methods for asserting page titles, OG meta tags,
 * meta descriptions, and JSON-LD structured data.
 *
 * For Pest tests, the global functions in tests/Pest.php are still available.
 * This trait is for PHPUnit-style test classes that need SEO assertions.
 */
trait SeoAssertions
{
    /**
     * Assert that the response contains a <title> tag with the expected name.
     * Only asserts the name portion — does not couple to the title suffix format
     * (separator, site name), so title template changes don't break tests.
     */
    protected function assertPageTitle(TestResponse $response, string $expectedName): void
    {
        $content = $response->content();
        preg_match('/<title>(.*?)<\/title>/', $content, $matches);
        $actual = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString($expectedName, $actual,
            "Expected page title to contain '{$expectedName}', got '{$actual}'");
    }

    /**
     * Assert that the response contains an OG meta tag property (content value not checked).
     */
    protected function assertOgMetaTagPresent(TestResponse $response, string $property): void
    {
        $content = $response->content();
        $this->assertStringContainsString("property=\"{$property}\"", $content,
            "Expected to find meta tag with property=\"{$property}\"");
    }

    /**
     * Extract the content attribute from a <meta name="description" content="..."> tag.
     */
    protected function extractMetaDescription(string $html): string
    {
        // Handle both attribute orders: name before content and content before name
        preg_match('/<meta\s+name="description"\s+content="([^"]*)"/', $html, $matches)
            || preg_match('/<meta\s+content="([^"]*)"\s+name="description"/', $html, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Extract all JSON-LD schemas from <script type="application/ld+json"> blocks.
     *
     * @return array<int, array>
     */
    protected function extractJsonLdSchemas(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
        $schemas = [];
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'JSON-LD parse error: ' . json_last_error_msg());
            $schemas[] = $decoded;
        }

        return $schemas;
    }

    /**
     * Find a specific JSON-LD schema by @type from an array of schemas.
     */
    protected function findSchemaByType(array $schemas, string $type): ?array
    {
        foreach ($schemas as $schema) {
            // Handle both single @type and @type arrays (e.g., ["Product", "AggregateRating"])
            $types = $schema['@type'] ?? [];
            $types = is_array($types) ? $types : [$types];
            if (in_array($type, $types)) {
                return $schema;
            }
            // Also check inside @graph arrays
            if (isset($schema['@graph'])) {
                foreach ($schema['@graph'] as $node) {
                    $nodeTypes = $node['@type'] ?? [];
                    $nodeTypes = is_array($nodeTypes) ? $nodeTypes : [$nodeTypes];
                    if (in_array($type, $nodeTypes)) {
                        return $node;
                    }
                }
            }
        }

        return null;
    }
}
