<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ExportDownloadController extends Controller
{
    /**
     * Download a user data export ZIP via a signed URL.
     *
     * The URL is signed with Laravel's signed URL mechanism and expires after 7 days.
     * The {user} parameter must match the user who owns the export file.
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

        // Find the most recent export file for this user
        $files = Storage::disk('local')->files('exports');
        $userFiles = collect($files)
            ->filter(fn (string $path) => str_starts_with($path, "exports/user-data-{$user->id}-"))
            ->sortDesc()
            ->values();

        if ($userFiles->isEmpty()) {
            Log::warning('export.download.file_not_found', [
                'user_id' => $user->id,
            ]);

            abort(404, 'Export file not found.');
        }

        $filePath = $userFiles->first();
        $fileName = basename($filePath);

        Log::info('export.download.served', [
            'user_id' => $user->id,
            'file_path' => $filePath,
            'file_size' => Storage::disk('local')->size($filePath),
            'ip' => $request->ip(),
        ]);

        return Storage::disk('local')->download($filePath, $fileName, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
