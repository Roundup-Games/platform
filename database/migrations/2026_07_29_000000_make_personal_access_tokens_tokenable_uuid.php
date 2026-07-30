<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Switch personal_access_tokens.tokenable_id from bigint to UUID.
     *
     * Sanctum's published migration uses morphs('tokenable'), which makes
     * tokenable_id a bigint. Every morph-owning model in this app uses UUID
     * primary keys (User, Game, Team, …), so createToken() threw
     * "invalid input syntax for type bigint" and bearer-token auth was
     * effectively non-functional — auth:sanctum only worked in SPA/session mode.
     *
     * dropMorphs + uuidMorphs is the documented Laravel pattern. There is no
     * real token data to preserve: the feature never produced a usable row.
     * Columns are recreated at the end of the table (cosmetic only).
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropMorphs('tokenable');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->uuidMorphs('tokenable');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropMorphs('tokenable');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->morphs('tokenable');
        });
    }
};
