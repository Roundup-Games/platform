<?php

namespace App\Http\Middleware;

use App\Services\Discord\DiscordInteractionVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Discord HTTP Interactions Ed25519 signature.
 *
 * Discord signs every interaction over (timestamp . rawBody) with the bot's
 * Ed25519 public key and runs automated security probes. This middleware is
 * the ONLY gate between the public internet and the controller — there is no
 * bypass path, because failing verification repeatedly gets the interactions
 * URL revoked (system DM + email alert).
 *
 * Reads `$request->getContent()` — the EXACT raw bytes Discord sent. NOT
 * re-serialized JSON: that changes byte order/whitespace and verification
 * fails. Laravel caches getContent(), so the controller can json_decode the
 * body afterward without re-reading the stream.
 *
 * On failure: 401 "invalid request signature" + the
 * `discord_interaction.signature_invalid` structured log event (trending signal
 * for Discord security probes / key misconfiguration / key rotation).
 */
class VerifyDiscordInteractionSignature
{
    public function __construct(
        private readonly DiscordInteractionVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->headers->get('X-Signature-Ed25519', '');
        $timestamp = $request->headers->get('X-Signature-Timestamp', '');
        $rawBody = $request->getContent();

        if (! $this->verifier->verify($rawBody, $signature, $timestamp)) {
            // Structured event: monitor rate for probes / key rotation needs.
            // Never log the body (may contain member data) — only shape signals.
            Log::info('discord_interaction.signature_invalid', [
                'has_signature_header' => $signature !== '',
                'has_timestamp_header' => $timestamp !== '',
                'signature_length' => strlen($signature),
            ]);

            return response('invalid request signature', 401);
        }

        return $next($request);
    }
}
