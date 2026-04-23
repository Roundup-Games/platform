<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert any remaining 'de+en' language values to 'de' across all tables.
     */
    public function up(): void
    {
        $conversions = [
            ['table' => 'games', 'column' => 'language'],
            ['table' => 'campaigns', 'column' => 'language'],
            ['table' => 'events', 'column' => 'content_language'],
            ['table' => 'users', 'column' => 'preferred_language'],
        ];

        foreach ($conversions as $target) {
            DB::table($target['table'])
                ->where($target['column'], 'de+en')
                ->update([$target['column'] => 'de']);
        }
    }

    /**
     * Reverse is not meaningful — 'de+en' is no longer a valid enum value.
     */
    public function down(): void
    {
        // No-op: de+en is removed from the enum and should not be restored.
    }
};
