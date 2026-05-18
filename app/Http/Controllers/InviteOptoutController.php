<?php

namespace App\Http\Controllers;

use App\Models\SuppressedInviteEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InviteOptoutController extends Controller
{
    /**
     * Show the opt-out confirmation page.
     *
     * Uses GET for the initial landing (email link), then POST for confirmation.
     * Two-step flow prevents email security scanners from triggering false suppression.
     */
    public function show(Request $request, string $emailHash)
    {
        // Validate the hash looks like a SHA-256 hex string
        if (! preg_match('/^[a-f0-9]{64}$/', $emailHash)) {
            Log::info('invite.optout.invalid_hash', [
                'ip' => $request->ip(),
            ]);

            return view('pages.invite-optout', ['status' => 'invalid']);
        }

        // Show confirmation page with POST form
        return view('pages.invite-optout', ['status' => 'confirm', 'emailHash' => $emailHash]);
    }

    /**
     * Confirm the opt-out after the user explicitly clicks the button.
     *
     * POST-only: email scanners and link prefetchers don't submit POST forms.
     * The emailHash is the SHA-256 of the invitee's email address.
     * Suppression is idempotent.
     */
    public function confirm(Request $request, string $emailHash)
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $emailHash)) {
            return view('pages.invite-optout', ['status' => 'invalid']);
        }

        SuppressedInviteEmail::firstOrCreate(
            ['email_hash' => $emailHash],
            ['created_at' => now()],
        );

        Log::info('invite.optout.confirmed', [
            'email_hash' => $emailHash,
            'ip' => $request->ip(),
        ]);

        return view('pages.invite-optout', ['status' => 'confirmed']);
    }
}
