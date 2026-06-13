<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a PostgreSQL trigger that auto-computes geohash_4 from latitude/longitude.
 *
 * The Location model's Eloquent `saving` hook already does this, but the trigger
 * provides a database-level safety net that catches inserts/updates made through
 * raw SQL, DB::table()->insert(), or any other path that bypasses Eloquent.
 *
 * The trigger fires BEFORE INSERT and UPDATE, computing a 4-character geohash
 * prefix whenever latitude and longitude are both non-null. If either is null,
 * geohash_4 is set to null.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PL/pgSQL function that encodes lat/lng to a geohash prefix.
        // Algorithm: standard geohash with interleaved lng/lat bits, base-32 encoding.
        $function = <<<'SQL'
            CREATE OR REPLACE FUNCTION locations_compute_geohash_4()
            RETURNS TRIGGER AS $$
            DECLARE
                v_lat_range double precision[] := ARRAY[-90.0, 90.0];
                v_lng_range double precision[] := ARRAY[-180.0, 180.0];
                v_is_lng boolean := true;
                v_bits integer := 0;
                v_bit_count integer := 0;
                v_mid double precision;
                v_bit_set boolean;
                v_chars text := '0123456789bcdefghjkmnpqrstuvwxyz';
                v_hash text := '';
                v_idx integer;
            BEGIN
                -- If either coordinate is null, clear geohash
                IF NEW.latitude IS NULL OR NEW.longitude IS NULL THEN
                    NEW.geohash_4 := NULL;
                    RETURN NEW;
                END IF;

                -- Encode 4 characters (4 × 5 bits = 20 bits)
                WHILE length(v_hash) < 4 LOOP
                    IF v_is_lng THEN
                        v_mid := (v_lng_range[1] + v_lng_range[2]) / 2.0;
                        IF NEW.longitude >= v_mid THEN
                            v_bits := v_bits | (16 >> v_bit_count);
                            v_lng_range[1] := v_mid;
                        ELSE
                            v_lng_range[2] := v_mid;
                        END IF;
                    ELSE
                        v_mid := (v_lat_range[1] + v_lat_range[2]) / 2.0;
                        IF NEW.latitude >= v_mid THEN
                            v_bits := v_bits | (16 >> v_bit_count);
                            v_lat_range[1] := v_mid;
                        ELSE
                            v_lat_range[2] := v_mid;
                        END IF;
                    END IF;

                    v_is_lng := NOT v_is_lng;

                    IF v_bit_count < 4 THEN
                        v_bit_count := v_bit_count + 1;
                    ELSE
                        v_idx := v_bits + 1; -- PostgreSQL strings are 1-indexed
                        v_hash := v_hash || substr(v_chars, v_idx, 1);
                        v_bits := 0;
                        v_bit_count := 0;
                    END IF;
                END LOOP;

                NEW.geohash_4 := v_hash;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

        DB::unprepared($function);

        DB::unprepared('
            CREATE TRIGGER locations_geohash_4_trigger
                BEFORE INSERT OR UPDATE OF latitude, longitude ON locations
                FOR EACH ROW
                EXECUTE FUNCTION locations_compute_geohash_4();
        ');

        // Backfill any existing locations that have coordinates but missing/stale geohash
        // The trigger will fire for each row, but we use a direct approach for efficiency
        DB::unprepared('
            UPDATE locations SET geohash_4 = NULL
            WHERE latitude IS NULL OR longitude IS NULL
        ');
        // For rows with coordinates, let the trigger handle it via a no-op update
        // We touch only rows that actually need fixing
        $locations = DB::table('locations')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($q) {
                $q->whereNull('geohash_4')
                    ->orWhere('geohash_4', '')
                    ->orWhereRaw('length(geohash_4) != 4');
            })
            ->get();

        foreach ($locations as $loc) {
            // Update with same values — trigger will compute geohash_4
            DB::table('locations')
                ->where('id', $loc->id)
                ->update([
                    'latitude' => $loc->latitude,
                    'longitude' => $loc->longitude,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS locations_geohash_4_trigger ON locations');
        DB::unprepared('DROP FUNCTION IF EXISTS locations_compute_geohash_4()');
    }
};
