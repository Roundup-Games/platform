<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EscalatedSettingsSeeder extends Seeder
{
    /**
     * Seed the default Escalated helpdesk settings.
     *
     * These were originally seeded inside two migrations
     * (2026_05_14_232552_create_escalated_settings_table and
     * 2026_05_14_232625_add_email_branding_settings) and later extended
     * via the admin UI in production. After the migration squash those
     * migrations are gone, so the defaults live here as the authoritative
     * baseline for fresh installs.
     */
    public function run(): void
    {
        $prefix = config('escalated.table_prefix', 'escalated_');

        $defaults = [
            ['key' => 'guest_tickets_enabled', 'value' => '1'],
            ['key' => 'allow_customer_close', 'value' => '1'],
            ['key' => 'auto_close_resolved_after_days', 'value' => '7'],
            ['key' => 'max_attachments_per_reply', 'value' => '5'],
            ['key' => 'max_attachment_size_kb', 'value' => '10240'],
            ['key' => 'email_logo_url', 'value' => null],
            ['key' => 'email_accent_color', 'value' => '#2d3748'],
            ['key' => 'email_footer_text', 'value' => null],
            ['key' => 'ticket_reference_prefix', 'value' => 'RGT'],
            ['key' => 'show_powered_by', 'value' => '1'],
        ];

        $now = now();

        foreach ($defaults as $setting) {
            DB::table($prefix.'settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
