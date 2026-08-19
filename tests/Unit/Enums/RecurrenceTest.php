<?php

use App\Enums\Recurrence;

/**
 * Contract test: Recurrence's declaration order IS the product contract.
 *
 * cases()/values() feed every user-facing enumeration (create-campaign form,
 * Filament admin, discovery filter) in declaration order, so the cases must
 * be declared shortest-to-longest interval, irregular last. If this test
 * fails, reorder the enum cases — never the consumers.
 */
describe('Recurrence enum', function () {
    it('declares values in calendar order: weekly, bi-weekly, monthly, custom', function () {
        expect(Recurrence::values())->toBe([
            'weekly',
            'bi-weekly',
            'monthly',
            'custom',
        ]);
    });

    it('keeps cases() and values() in the same order (labels render in calendar order)', function () {
        $cases = array_map(fn (Recurrence $case) => $case->value, Recurrence::cases());

        expect($cases)->toBe(Recurrence::values());
    });
});
