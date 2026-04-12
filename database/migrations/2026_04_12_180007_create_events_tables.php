<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->enum('type', ['tournament', 'league', 'camp', 'clinic', 'social', 'other'])->default('tournament');
            $table->enum('status', [
                'draft', 'published', 'registration_open', 'registration_closed',
                'in_progress', 'completed', 'cancelled',
            ])->default('draft');
            $table->string('venue_name')->nullable();
            $table->text('venue_address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 3)->nullable();
            $table->string('postal_code')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->enum('registration_type', ['team', 'individual', 'both'])->default('team');
            $table->unsignedInteger('max_teams')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedInteger('min_players_per_team')->default(7);
            $table->unsignedInteger('max_players_per_team')->default(21);
            $table->unsignedInteger('team_registration_fee')->default(0); // cents
            $table->unsignedInteger('individual_registration_fee')->default(0); // cents
            $table->unsignedInteger('early_bird_discount')->nullable(); // cents
            $table->timestamp('early_bird_deadline')->nullable();
            $table->foreignId('organizer_id')->constrained('users');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->json('rules')->nullable();
            $table->json('schedule')->nullable();
            $table->json('divisions')->nullable();
            $table->json('amenities')->nullable();
            $table->json('requirements')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->enum('registration_type', ['team', 'individual']);
            $table->string('division', 100)->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('payment_status', 50)->default('pending');
            $table->string('payment_id')->nullable();
            $table->json('roster')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('event_announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->foreignId('author_id')->constrained('users');
            $table->string('title');
            $table->text('content');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_published')->default(false);
            $table->string('visibility', 50)->default('all');
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_announcements');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
