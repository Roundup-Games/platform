<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Featured-badge flag on badge_user (M069 S04, spec §2.4): the profile
 * shelf renders user-selected featured badges prominently (max 3).
 *
 * Max-3 is enforced in the Livewire action (toggleFeatured), NOT in the
 * schema: a partial-unique-style constraint over "featured" rows would
 * over-complicate the lifecycle semantics — featuring, unfeaturing and
 * revocation are ordinary row updates, and the 4th feature must surface
 * as the shelf's loud validation message, not a database error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badge_user', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('badge_user', function (Blueprint $table): void {
            $table->dropColumn(['is_featured']);
        });
    }
};
