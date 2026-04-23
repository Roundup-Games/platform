<?php

use App\Enums\ContentLanguage;

describe('ContentLanguage enum', function () {
    it('has exactly 2 cases', function () {
        expect(ContentLanguage::cases())->toHaveCount(2);
    });

    it('returns correct values', function () {
        expect(ContentLanguage::values())->toBe(['en', 'de']);
    });

    it('does not contain de+en in values', function () {
        expect(ContentLanguage::values())->not->toContain('de+en');
    });

    it('returns display labels for each case', function () {
        expect(ContentLanguage::En->label())->toBe('English');
        expect(ContentLanguage::De->label())->toBe('German');
    });

    it('is backed by string type', function () {
        $reflection = new ReflectionEnum(ContentLanguage::class);
        expect($reflection->getBackingType()?->getName())->toBe('string');
    });

    it('can be instantiated from a string value', function () {
        expect(ContentLanguage::from('en'))->toBe(ContentLanguage::En);
        expect(ContentLanguage::from('de'))->toBe(ContentLanguage::De);
    });

    it('rejects de+en as invalid value', function () {
        expect(fn () => ContentLanguage::from('de+en'))
            ->toThrow(ValueError::class);
    });
});
