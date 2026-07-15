<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with baseline reference data.
     *
     * Fresh installs start with zero users — the operator creates the
     * first admin via `php artisan admin:user-create`.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            MembershipTypeSeeder::class,
            EscalatedSetupSeeder::class,
            EscalatedSettingsSeeder::class,
            StartPlayingSeeder::class,
        ]);
    }
}
