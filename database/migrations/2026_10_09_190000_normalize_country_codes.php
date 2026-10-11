<?php

use App\Support\Country;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Normalize stored country values to the ISO 3166-1 alpha-3 standard
 * (D173). Free-text intake let ISO-2 ('DE', 'CH', 'AT') and mixed-case
 * values into locations.country (live-deployment audit: DE=15 vs DEU=7,
 * AUT/CHE mixed with AT/CH, one empty) — splitting country grouping and
 * filters.
 *
 * Alpha-2 values upgrade through symfony/intl (deterministic, pinned);
 * already-alpha-3 values pass through normalize() (upper+trim) so mixed
 * casing is also cured. Empty stays empty (flagged in the log — a venue
 * without a country needs geocoding, not a guess). Values that are
 * neither valid alpha-2 nor alpha-3 are left untouched and logged for
 * manual review — never silently remapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('locations')->whereNotNull('country')->distinct()->get(['country']);

        foreach ($rows as $row) {
            $original = (string) $row->country;
            $upgraded = Country::upgradeToAlpha3($original);

            if ($upgraded === $original || $upgraded === null) {
                continue;
            }

            if (! Country::isValid($upgraded)) {
                Log::warning('country migration: unrecognized value left as-is', [
                    'country' => $original,
                    'hint' => 'Manual review — not a valid ISO 3166-1 code.',
                ]);

                continue;
            }

            DB::table('locations')
                ->where('country', $original)
                ->update(['country' => $upgraded]);

            Log::info('country migration: normalized', [
                'from' => $original,
                'to' => $upgraded,
            ]);
        }

        // Registry rows carry denormalized labels from their locations —
        // refresh them through the same standard.
        DB::table('cities')->whereNotNull('country')->update([
            'country' => DB::raw('btrim(upper(country))'),
        ]);
    }

    public function down(): void
    {
        // Not reversible: the original mixed-case/ISO-2 values are gone by
        // design, and downgrading to them would reintroduce the split.
    }
};
