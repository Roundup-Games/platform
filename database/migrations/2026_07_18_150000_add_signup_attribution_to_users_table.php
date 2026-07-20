<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist signup acquisition attribution on the user row.
 *
 * Closes the attribution blindness flagged in the M056 CONTEXT: roundup
 * already derives five signup-attribution signals in-session
 * (CaptureFirstTouch middleware + PostHogAnalytics::identifyFirstTouch +
 * the OAuth provider on the signup request), but until now they were only
 * shipped to PostHog (consent-gated, pseudonymized) and never persisted
 * locally. This migration lands them as five write-once columns on users
 * so the admin-only SignupAttributionReport can surface them.
 *
 * Columns (all nullable, write-once at signup — never overwritten on login):
 *
 *   signup_oauth_provider       'google' | 'discord' | 'email'
 *                               (always set at signup; nullable for safety)
 *   first_touch_referer_domain  hostname parsed from the Referer header that
 *                               brought the visitor to roundup (PII-free:
 *                               query string discarded, host-only)
 *   first_touch_path            public content path the visitor first landed
 *                               on (e.g. /en/games/{slug})
 *   signup_content_type         detected content kind: 'game' | 'campaign' |
 *                               'venue' (null for non-content landings)
 *   signup_content_slug         slug of the detected content (null when
 *                               signup_content_type is null)
 *
 * Privacy posture: referer is reduced to hostname only (full URLs may carry
 * UTM/PII in query strings); the path is the public landing path only. These
 * are admin-queryable via Filament (canAccessPanel-gated) and never exposed
 * to other users. They duplicate data already captured by PostHog consent-
 * gated identifyFirstTouch — this migration persists it locally so it is
 * queryable without crossing the PostHog boundary.
 *
 * Idempotent guard mirrors add_last_login_at_to_users_table: the squashed
 * schema baseline (database/schema/pgsql-schema.sql) is updated to declare
 * these columns for fresh installs. The hasColumn checks prevent a duplicate-
 * column error on databases where the baseline already created them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'signup_oauth_provider')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('signup_oauth_provider', 50)->nullable()
                    ->after('last_login_at');
            });
        }

        if (! Schema::hasColumn('users', 'first_touch_referer_domain')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('first_touch_referer_domain', 255)->nullable()
                    ->after('signup_oauth_provider');
            });
        }

        if (! Schema::hasColumn('users', 'first_touch_path')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('first_touch_path', 255)->nullable()
                    ->after('first_touch_referer_domain');
            });
        }

        if (! Schema::hasColumn('users', 'signup_content_type')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('signup_content_type', 50)->nullable()
                    ->after('first_touch_path');
            });
        }

        if (! Schema::hasColumn('users', 'signup_content_slug')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('signup_content_slug', 255)->nullable()
                    ->after('signup_content_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('users', 'signup_oauth_provider') ? 'signup_oauth_provider' : null,
                Schema::hasColumn('users', 'first_touch_referer_domain') ? 'first_touch_referer_domain' : null,
                Schema::hasColumn('users', 'first_touch_path') ? 'first_touch_path' : null,
                Schema::hasColumn('users', 'signup_content_type') ? 'signup_content_type' : null,
                Schema::hasColumn('users', 'signup_content_slug') ? 'signup_content_slug' : null,
            ], fn ($c) => $c !== null);

            if (! empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
