<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->text('dispute_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->string('dispute_reason')->nullable()->change();
        });
    }
};
