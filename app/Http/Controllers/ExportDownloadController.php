<?php

namespace App\Http\Controllers;

use App\Models\User;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ExportDownloadController extends Controller
{
    /**
     * Download a user data export ZIP via a signed URL.
     *
     * The URL is signed with Laravel's signed URL mechanism and includes both
     * the user ID and a file token derived from the export path. This prevents:
     * - Stale signed URLs from serving a different (newer) export
     * - Signed URL leakage from granting access to re-generated exports
     *
     * The signed URL expires after 7 days.
     *
     * @return StreamedResponse|RedirectResponse
     */
    public function download(Request $request, string $locale, User $user)
    {
        if (! $request->hasValidSignature()) {
            Log::warning('export.download.invalid_signature', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            throw new AccessDeniedHttpException('This download link has expired or is invalid.');
        }

        // Ensure the authenticated user can only download their own export.
        // Combined with the signed URL, this prevents export leakage via
        // browser history, URL forwarding, or log exposure.
        if (auth()->id() !== $user->id) {
            Log::warning('export.download.user_mismatch', [
                'auth_user_id' => auth()->id(),
                'requested_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            throw new AccessDeniedHttpException('You are not authorized to download this export.');
        }

        // Resolve the export file from the most recently created resolved ticket for this user
        $ticket = Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->where('ticket_type', 'data_export_request')
            ->whereNotNull('metadata->export_path')
            ->orderByDesc('created_at')
            ->first();

        if (! $ticket || ! isset($ticket->metadata['export_path'])) {
            Log::warning('export.download.no_ticket', [
                'user_id' => $user->id,
            ]);

            abort(404, 'Export file not found.');
        }

        $filePath = $ticket->metadata['export_path'];
        if (! is_string($filePath)) {
            abort(404, 'Export file not found.');
        }

        // Validate the file token from the signed URL matches the resolved export.
        // This prevents a stale signed URL (from a previous export) from downloading
        // a newer export that was generated after the URL was created.
        $fileToken = $request->query('token');
        $expectedToken = $this->deriveFileToken($filePath);

        if (! $fileToken || ! hash_equals($expectedToken, $fileToken)) {
            Log::warning('export.download.token_mismatch', [
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'file_path' => $filePath,
            ]);

            throw new AccessDeniedHttpException('This download link does not match the current export.');
        }

        // Defense-in-depth: ensure the path is within the exports directory.
        // The path is set by admin action, but an explicit guard prevents
        // serving arbitrary files if metadata is ever tampered with.
        if (! str_starts_with($filePath, 'exports/') || str_contains($filePath, '..')) {
            Log::warning('export.download.invalid_path', [
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
            ]);

            abort(403, 'Invalid export path.');
        }

        // Secondary validation: resolve the real filesystem path and confirm
        // it is within the storage exports directory. This catches any edge
        // case that bypasses the prefix check above.
        // Note: realpath() returns false for virtual/faked storage in tests;
        // the primary prefix check above is the authoritative guard in that case.
        $realBase = realpath(storage_path('app/exports'));
        $realPath = realpath(storage_path('app/'.$filePath));

        if ($realBase !== false && $realPath !== false && ! str_starts_with($realPath, $realBase.'/')) {
            Log::warning('export.download.path_escape', [
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'file_path' => $filePath,
            ]);

            abort(403, 'Invalid export path.');
        }

        if (! Storage::disk('local')->exists($filePath)) {
            Log::warning('export.download.file_missing', [
                'user_id' => $user->id,
                'file_path' => $filePath,
            ]);

            abort(404, 'Export file not found.');
        }

        $fileName = basename($filePath);

        Log::info('export.download.served', [
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
            'file_path' => $filePath,
            'file_size' => Storage::disk('local')->size($filePath),
            'ip' => $request->ip(),
        ]);

        return Storage::disk('local')->download($filePath, $fileName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Derive a deterministic token from an export file path.
     *
     * Uses HMAC-SHA256 with the app key so the token is:
     * - Deterministic (same path always produces the same token)
     * - Unforgeable (requires app.key to reproduce)
     * - Truncated to 16 chars for clean URLs
     */
    public static function deriveFileToken(string $filePath): string
    {
        $key = config('app.key');

        return substr(hash_hmac('sha256', $filePath, is_string($key) ? $key : ''), 0, 16);
    }
}
