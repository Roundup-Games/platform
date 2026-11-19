<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-on-write defer window storage (M082/S01, D296).
 *
 * Knock's canonical batched-notification schema (notification_batches +
 * pivot, recipient × type × batch_key, closes_at/processed_at), adapted
 * to platform conventions: uuidv7 primary keys via HasPlatformUuid
 * (D170/INV-10), native uuid morph columns under the enforced morph map
 * (INV-11), jsonb payloads for the per-event snapshots.
 *
 * notification_batches — ONE OPEN ROW per (recipient, batch_key): the
 * partial unique index below enforces it while processed_at IS NULL.
 * Buffering appends to the open row (NotificationService, T04); flush
 * marks it processed (T03), which releases the key for the next burst.
 * The open() scope deliberately ignores closes_at — an event arriving
 * after closes_at but before the flush ran joins the imminent batch
 * (appending cannot create a second open row without violating the
 * index, and a lost append would silently swallow the event).
 *
 * notification_batch_items — immutable append-only snapshot rows, one
 * per buffered event. payload carries the serialized event context the
 * batched rendering (S02) needs: actor name/id, entity name/type, event
 * context, created_at. Rows carry created_at only (no updated_at): a
 * snapshot is never edited. Deleting a batch cascades to its items —
 * the buffer dies with its batch.
 *
 * flush_bounds: flush_attempts counts sweep dispatches (bounded at 5 by
 * the notifications:flush-batches command, T03) — the delayed-job path
 * alone is not durable on the ephemeral redis queue; the every-minute
 * sweep is the durable second flush path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('recipient_type');
            $table->uuid('recipient_id');
            $table->string('category', 64);
            $table->string('batch_key');
            $table->timestamp('closes_at');
            $table->timestamp('processed_at')->nullable();
            $table->integer('flush_attempts')->default(0);
            $table->timestamps();
        });

        // One OPEN batch per (recipient_type, recipient_id, batch_key):
        // the partial predicate (processed_at IS NULL) releases the key
        // the moment a flush marks the batch processed, so the next burst
        // opens a fresh 5-minute window under the same key. Built as a
        // raw statement — the schema grammars do not compile WHERE
        // clauses on index definitions (the baseline's other partial
        // indexes, e.g. campaign_participants_invite_email_unique, were
        // created the same way). recipient_type leads the key so two
        // morph types sharing a uuid can never collide.
        DB::statement(
            'CREATE UNIQUE INDEX notification_batches_open_batch_unique '
            .'ON notification_batches (recipient_type, recipient_id, batch_key) '
            .'WHERE processed_at IS NULL'
        );

        Schema::create('notification_batch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('notification_batches')->cascadeOnDelete();
            $table->jsonb('payload');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_batch_items');
        Schema::dropIfExists('notification_batches');
    }
};
