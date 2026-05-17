<?php

namespace App\Http\Controllers;

use App\Models\SuppressedInviteEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InviteOptoutController extends Controller
{
    /**
     * Handle one-click unsubscribe from invite emails.
     *
     * The emailHash is the SHA-256 of the invitee's email address.
     * If valid, suppress the email (idempotent) and show confirmation.
     * If invalid, show a generic error.
     */
    public function optout(Request $request, string $emailHash)
    {
        // Validate the hash looks like a SHA-256 hex string
        if (! preg_match('/^[a-f0-9]{64}$/', $emailHash)) {
            Log::info('invite.optout.invalid_hash', [
                'ip' => $request->ip(),
            ]);

            return view('pages.invite-optout', ['status' => 'invalid']);
        }

        // Suppress the email by hash — idempotent
        SuppressedInviteEmail::firstOrCreate(
            ['email_hash' => $emailHash],
            ['created_at' => now()],
        );

        Log::info('invite.optout.success', [
            'email_hash' => $emailHash,
            'ip' => $request->ip(),
        ]);

        return view('pages.invite-optout', ['status' => 'confirmed']);
    }
}
