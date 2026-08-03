<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email suppression list — addresses that must no longer receive mail.
 *
 * Populated by the Resend webhook (app/Http/Controllers/ResendWebhookController)
 * when an address hard-bounces (email.bounced with a Permanent bounce type) or
 * generates a spam complaint (email.complained). NotificationService consults
 * EmailSuppression::isSuppressed() at channel-resolution time and drops the
 * mail channel for suppressed recipients, so we never hand Resend an address it
 * already told us is undeliverable — protecting sender reputation and respecting
 * complainers (CAN-SPAM/GDPR).
 *
 * Unique on email (lowercased) so repeated bounce/complaint events for the same
 * address are idempotent: firstOrCreate, never duplicate rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Suppressed address, stored lowercased for case-insensitive matching.
            // Indexed unique — the natural lookup key and the idempotency guard.
            $table->string('email')->unique();

            // Why the address was suppressed: 'hard_bounce' | 'complaint'.
            $table->string('reason');

            // Provenance — which system added the suppression. Today always
            // 'resend_webhook'; a future admin UI or API could add others.
            $table->string('source')->default('resend_webhook');

            // The Resend event that triggered the suppression (email_id and/or
            // RFC message_id), retained for traceability back to the exact
            // delivery attempt. Nullable since a manual suppression has none.
            $table->string('trigger_message_id')->nullable();

            $table->timestamp('suppressed_at')->useCurrent();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
