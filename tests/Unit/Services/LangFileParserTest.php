<?php

use App\Services\LangFileParser;

beforeEach(function () {
    $this->parser = new LangFileParser;
    $this->testLocale = '_test_parser';
    $this->testDir = lang_path($this->testLocale);

    // Create a temp locale directory for edge-case tests
    if (! is_dir($this->testDir)) {
        mkdir($this->testDir, 0755, true);
    }
});

afterEach(function () {
    // Clean up temp locale directory
    if (is_dir($this->testDir)) {
        $files = glob($this->testDir . '/*.php');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->testDir);
    }
});

function writeTempLangFile(string $dir, string $domain, string $content): string
{
    $path = "$dir/$domain.php";
    file_put_contents($path, $content);

    return $path;
}

describe('parseDomain', function () {
    it('parses a valid PHP file and flattens nested keys', function () {
        writeTempLangFile($this->testDir, 'flat', <<<PHP
<?php
return [
    'key1' => 'value1',
    'nested' => [
        'child' => 'value2',
    ],
];
PHP);

        $result = $this->parser->parseDomain($this->testLocale, 'flat');

        expect($result)->toHaveKey('key1');
        expect($result['key1'])->toBe('value1');
        expect($result)->toHaveKey('nested.child');
        expect($result['nested.child'])->toBe('value2');
    });

    it('returns empty array for empty PHP file', function () {
        writeTempLangFile($this->testDir, 'empty', "<?php\nreturn [];\n");

        $result = $this->parser->parseDomain($this->testLocale, 'empty');

        expect($result)->toBe([]);
    });

    it('returns empty array for missing file', function () {
        $result = $this->parser->parseDomain($this->testLocale, 'nonexistent');

        expect($result)->toBe([]);
    });

    it('throws ParseError for malformed PHP', function () {
        writeTempLangFile($this->testDir, 'malformed', "<?php\nreturn [\n  'key' =>\n");

        expect(fn () => $this->parser->parseDomain($this->testLocale, 'malformed'))
            ->toThrow(ParseError::class);
    });

    it('returns empty array for file returning non-array', function () {
        writeTempLangFile($this->testDir, 'scalar', "<?php\nreturn 'not an array';\n");

        $result = $this->parser->parseDomain($this->testLocale, 'scalar');

        expect($result)->toBe([]);
    });

    it('parses values with escaped quotes correctly', function () {
        writeTempLangFile($this->testDir, 'escaped', "<?php\nreturn [\n  'key' => 'It\\'s a test',\n];\n");

        $result = $this->parser->parseDomain($this->testLocale, 'escaped');

        expect($result)->toHaveKey('key');
        expect($result['key'])->toBe("It's a test");
    });

    it('flattens deeply nested arrays to dotted notation', function () {
        writeTempLangFile($this->testDir, 'deep', <<<PHP
<?php
return [
    'a' => [
        'b' => [
            'c' => 'deep_value',
        ],
    ],
];
PHP);

        $result = $this->parser->parseDomain($this->testLocale, 'deep');

        expect($result)->toHaveKey('a.b.c');
        expect($result['a.b.c'])->toBe('deep_value');
    });

    it('handles mixed string and nested array values', function () {
        writeTempLangFile($this->testDir, 'mixed', <<<PHP
<?php
return [
    'simple' => 'val',
    'nested' => [
        'child' => 'val2',
    ],
];
PHP);

        $result = $this->parser->parseDomain($this->testLocale, 'mixed');

        expect($result)->toHaveKey('simple');
        expect($result['simple'])->toBe('val');
        expect($result)->toHaveKey('nested.child');
        expect($result['nested.child'])->toBe('val2');
        expect($result)->toHaveCount(2);
    });

    it('handles numeric keys in arrays', function () {
        writeTempLangFile($this->testDir, 'numeric', <<<PHP
<?php
return [
    'items' => ['first', 'second'],
];
PHP);

        $result = $this->parser->parseDomain($this->testLocale, 'numeric');

        // Arr::dot produces items.0 and items.1 for numeric-indexed arrays
        expect($result)->toHaveKey('items.0');
        expect($result)->toHaveKey('items.1');
        expect($result['items.0'])->toBe('first');
        expect($result['items.1'])->toBe('second');
    });

    it('handles boolean and null values', function () {
        writeTempLangFile($this->testDir, 'types', <<<PHP
<?php
return [
    'flag' => true,
    'empty' => null,
    'off' => false,
];
PHP);

        $result = $this->parser->parseDomain($this->testLocale, 'types');

        expect($result)->toHaveKey('flag');
        expect($result['flag'])->toBeTrue();
        expect($result)->toHaveKey('empty');
        expect($result['empty'])->toBeNull();
        expect($result)->toHaveKey('off');
        expect($result['off'])->toBeFalse();
    });
});

describe('getKeys', function () {
    it('returns flat key list from a domain file', function () {
        writeTempLangFile($this->testDir, 'keys_test', <<<PHP
<?php
return [
    'action_save' => 'Save',
    'field_name' => 'Name',
    'nested' => [
        'child' => 'Child',
    ],
];
PHP);

        $keys = $this->parser->getKeys($this->testLocale, 'keys_test');

        expect($keys)->toContain('action_save');
        expect($keys)->toContain('field_name');
        expect($keys)->toContain('nested.child');
        expect($keys)->toHaveCount(3);
    });

    it('returns empty array for missing domain', function () {
        $keys = $this->parser->getKeys($this->testLocale, 'no_such_domain');

        expect($keys)->toBe([]);
    });
});

describe('findDuplicateKeys', function () {
    it('detects duplicate top-level keys', function () {
        writeTempLangFile($this->testDir, 'dupes', <<<PHP
<?php
return [
    'action_save' => 'Save',
    'action_save' => 'Save (duplicate)',
    'field_name' => 'Name',
];
PHP);

        $dupes = $this->parser->findDuplicateKeys($this->testLocale, 'dupes');

        expect($dupes)->toHaveKey('action_save');
        expect($dupes['action_save'])->toBe(2);
        expect($dupes)->toHaveCount(1);
    });

    it('returns empty for file with no duplicates', function () {
        writeTempLangFile($this->testDir, 'clean', "<?php\nreturn [\n  'a' => 'A',\n  'b' => 'B',\n];\n");

        $dupes = $this->parser->findDuplicateKeys($this->testLocale, 'clean');

        expect($dupes)->toBe([]);
    });

    it('returns empty for missing file', function () {
        $dupes = $this->parser->findDuplicateKeys($this->testLocale, 'nonexistent');

        expect($dupes)->toBe([]);
    });
});

describe('validateKeyConvention', function () {
    it('accepts keys with valid prefixes', function () {
        $violations = $this->parser->validateKeyConvention('action_save');

        expect($violations)->toBe([]);
    });

    it('rejects keys without recognized prefix', function () {
        $violations = $this->parser->validateKeyConvention('save');

        expect($violations)->not->toBeEmpty();
    });

    it('rejects keys with uppercase characters', function () {
        $violations = $this->parser->validateKeyConvention('action_Save');

        expect($violations)->not->toBeEmpty();
    });

    it('rejects keys containing dots', function () {
        $violations = $this->parser->validateKeyConvention('action.some.save');

        expect($violations)->not->toBeEmpty();
    });

    it('allows framework keys in auth domain', function () {
        $violations = $this->parser->validateKeyConvention('failed', 'auth');

        expect($violations)->toBe([]);
    });
});

describe('isUntranslated', function () {
    it('flags empty translations as untranslated', function () {
        expect($this->parser->isUntranslated('Hello', ''))->toBeTrue();
    });

    it('flags identical values as untranslated for non-cognates', function () {
        expect($this->parser->isUntranslated('Save', 'Save'))->toBeTrue();
    });

    it('does not flag identical values for known cognates', function () {
        expect($this->parser->isUntranslated('Team', 'Team'))->toBeFalse();
    });

    it('does not flag different translations', function () {
        expect($this->parser->isUntranslated('Save', 'Speichern'))->toBeFalse();
    });

    it('does not flag brand names', function () {
        expect($this->parser->isUntranslated('Roundup Games', 'Roundup Games'))->toBeFalse();
    });
});

describe('getLocales and getDomains', function () {
    it('getLocales returns configured locales', function () {
        $locales = $this->parser->getLocales();

        expect($locales)->toContain('en');
    });

    it('getPrimaryLocale returns fallback locale', function () {
        $primary = $this->parser->getPrimaryLocale();

        expect($primary)->toBe('en');
    });

    it('getDomains returns domain names from primary locale', function () {
        $domains = $this->parser->getDomains();

        // Real lang files exist, so we should get some domains
        expect($domains)->not->toBeEmpty();
        expect($domains)->toContain('common');
        expect($domains)->toContain('events');
    });

    it('getDomains returns sorted list', function () {
        $domains = $this->parser->getDomains();
        $sorted = $domains;
        sort($sorted);

        expect($domains)->toBe($sorted);
    });
});

describe('getAllDomains', function () {
    it('returns domains from all locales deduplicated and sorted', function () {
        $domains = $this->parser->getAllDomains();

        expect($domains)->not->toBeEmpty();
        // Should be sorted
        $sorted = $domains;
        sort($sorted);
        expect($domains)->toBe($sorted);
    });
});
