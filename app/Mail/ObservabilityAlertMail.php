<?php

namespace App\Mail;

use App\Models\AlertableError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\SerializesModels;

/**
 * Operator mail for the log-derived alerting backbone (observability
 * 2026-11-03). One class, two types:
 *
 *  - 'alert'  — observability:scan's consolidated first page for critical
 *               pattern matches that fired within 24h.
 *  - 'digest' — the daily unresolved summary, grouped by level in the view.
 *
 * Internal ops mail for the operator: plain EN strings by convention —
 * no lang keys, no locale handling. Rows serialize by id via
 * SerializesModels and are re-hydrated on the queue worker.
 */
#[Backoff([10, 60])]
class ObservabilityAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $type  'alert' (scan page) or 'digest' (daily summary).
     * @param  list<AlertableError>  $rows  The alertable_errors rows included.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $rows,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->type === 'digest'
                ? sprintf('Observability digest — %d unresolved log error(s)', count($this->rows))
                : sprintf('Observability alert — %d critical log pattern(s) firing', count($this->rows)),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: $this->type === 'digest'
                ? 'emails.observability-digest'
                : 'emails.observability-alert',
        );
    }
}
