<?php

use App\Http\Controllers\ExportDownloadController;
use App\Livewire\Settings\Show;
use App\Livewire\Support\ContactSupport;
use App\Models\LinkedAccount;
use App\Models\User;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->accountSupportDept = Department::factory()->create(['name' => 'Account Support']);
    Tag::factory()->create(['name' => 'data-export', 'color' => '#10B981']);
    Tag::factory()->create(['name' => 'account-recovery', 'color' => '#2563EB']);
});

// ── Profile requestExport() ──────────────────────────

describe('Profile Data Export Request', function () {
    it('creates a data_export_request ticket from profile', function () {
        $component = Livewire::actingAs($this->user)
            ->test(Show::class);

        $component->call('requestExport');
        $component->assertHasNoErrors();

        $ticket = Ticket::where('ticket_type', 'data_export_request')
            ->where('requester_id', $this->user->id)
            ->first();

        expect($ticket)->not->toBeNull()
            ->and($ticket->ticket_type)->toBe('data_export_request')
            ->and($ticket->department_id)->toBe($this->accountSupportDept->id)
            ->and($ticket->priority)->toBe(TicketPriority::Medium)
            ->and($ticket->channel)->toBe(TicketChannel::Web)
            ->and($ticket->metadata['schema'])->toBe('data_export/v1')
            ->and($ticket->metadata['actor']['id'])->toBe($this->user->id);
    });

    it('prevents duplicate open export requests', function () {
        $component = Livewire::actingAs($this->user)
            ->test(Show::class);
        $component->call('requestExport');
        $component->assertHasNoErrors();

        // Second request should be blocked
        $component->call('requestExport');
        $component->assertHasErrors(['dataExport']);

        expect(
            Ticket::where('ticket_type', 'data_export_request')
                ->where('requester_id', $this->user->id)
                ->count()
        )->toBe(1);
    });

    it('allows new request after existing one is resolved', function () {
        $component = Livewire::actingAs($this->user)
            ->test(Show::class);
        $component->call('requestExport');

        // Resolve the ticket
        $ticket = Ticket::where('ticket_type', 'data_export_request')
            ->where('requester_id', $this->user->id)
            ->first();
        $ticket->update(['status' => TicketStatus::Resolved->value]);

        // Reload component — should see no pending request now
        $component = Livewire::actingAs($this->user)
            ->test(Show::class);
        $component->call('requestExport');
        $component->assertHasNoErrors();

        expect(
            Ticket::where('ticket_type', 'data_export_request')
                ->where('requester_id', $this->user->id)
                ->count()
        )->toBe(2);
    });
});

// ── GenerateUserDataExport Command ───────────────────

describe('Export Command', function () {
    it('generates a ZIP file and stores it', function () {
        Storage::fake('local');

        $exitCode = Artisan::call('export:user-data', ['user' => $this->user->id]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(0, "Export command failed. Output: {$output}");

        $lines = explode("\n", $output);
        $storedPath = end($lines);

        expect($storedPath)->toStartWith('exports/');
        Storage::disk('local')->assertExists($storedPath);
    });

    it('includes all expected data files in the ZIP', function () {
        Storage::fake('local');

        Artisan::call('export:user-data', ['user' => $this->user->id]);
        $output = trim(Artisan::output());
        $lines = explode("\n", $output);
        $storedPath = end($lines);

        $fileNames = extractZipFileNames($storedPath);

        $expectedFiles = [
            'manifest.json',
            'profile.json',
            'linked-accounts.json',
            'games.json',
            'campaigns.json',
            'events.json',
            'reviews.json',
            'teams.json',
            'activity-log.json',
            'push-subscriptions.json',
            'social-links.json',
        ];

        foreach ($expectedFiles as $expectedFile) {
            expect($fileNames)->toContain($expectedFile);
        }
    });

    it('produces a manifest with correct schema', function () {
        Storage::fake('local');

        Artisan::call('export:user-data', ['user' => $this->user->id]);
        $output = trim(Artisan::output());
        $lines = explode("\n", $output);
        $storedPath = end($lines);

        $manifest = extractJsonFromZip($storedPath, 'manifest.json');

        expect($manifest)->toHaveKey('export_date')
            ->and($manifest['user_id'])->toBe($this->user->id)
            ->and($manifest['schema_version'])->toBe('1.0.0')
            ->and($manifest['files'])->not->toBeEmpty();

        foreach ($manifest['files'] as $fileEntry) {
            expect($fileEntry)->toHaveKey('path')
                ->and($fileEntry)->toHaveKey('sha256')
                ->and($fileEntry['sha256'])->toMatch('/^[a-f0-9]{64}$/');
        }
    });

    it('produces checksums that match actual file contents', function () {
        Storage::fake('local');

        Artisan::call('export:user-data', ['user' => $this->user->id]);
        $output = trim(Artisan::output());
        $lines = explode("\n", $output);
        $storedPath = end($lines);

        $manifest = extractJsonFromZip($storedPath, 'manifest.json');

        $zipContent = Storage::disk('local')->get($storedPath);
        $tempZip = writeTempZip($zipContent);

        $zip = new ZipArchive;
        $zip->open($tempZip);

        foreach ($manifest['files'] as $fileEntry) {
            $content = $zip->getFromName($fileEntry['path']);
            expect($content)->not->toBeFalse("Failed to read {$fileEntry['path']} from ZIP");
            $actualHash = hash('sha256', $content);
            expect($actualHash)->toBe($fileEntry['sha256'], "Checksum mismatch for {$fileEntry['path']}");
        }

        $zip->close();
        unlink($tempZip);
    });

    it('excludes sensitive attributes from profile export', function () {
        Storage::fake('local');

        Artisan::call('export:user-data', ['user' => $this->user->id]);
        $output = trim(Artisan::output());
        $lines = explode("\n", $output);
        $storedPath = end($lines);

        $profile = extractJsonFromZip($storedPath, 'profile.json');

        expect($profile)->not->toHaveKey('password')
            ->and($profile)->not->toHaveKey('remember_token')
            ->and($profile)->not->toHaveKey('paddle_id')
            ->and($profile['id'])->toBe($this->user->id)
            ->and($profile['name'])->toBe($this->user->name)
            ->and($profile['email'])->toBe($this->user->email);
    });

    it('fails for a non-existent user UUID', function () {
        // Use a valid UUID format to avoid PostgreSQL UUID type errors
        $exitCode = Artisan::call('export:user-data', ['user' => Str::uuid()->toString()]);

        expect($exitCode)->toBe(1);
    });

    it('does not include other users data in the export', function () {
        Storage::fake('local');

        // Create another user with a distinctive name and email
        $otherUser = User::factory()->create([
            'name' => 'Other User UniqueName '.Str::random(8),
            'email' => 'other-'.Str::random(8).'@example.com',
        ]);

        // Generate export for $this->user
        Artisan::call('export:user-data', ['user' => $this->user->id]);
        $output = trim(Artisan::output());
        $lines = explode("\n", $output);
        $storedPath = end($lines);

        // Verify profile export only contains the requesting user's data
        $profile = extractJsonFromZip($storedPath, 'profile.json');
        expect($profile['id'])->toBe($this->user->id);

        // Ensure other user's email/name do not appear anywhere in the export files.
        // This catches data leaks in all gatherers (games, campaigns, teams, etc.)
        $zipContent = Storage::disk('local')->get($storedPath);
        $tempZip = writeTempZip($zipContent);
        $zip = new ZipArchive;
        $zip->open($tempZip);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileName = $zip->getNameIndex($i);
            $content = $zip->getFromName($fileName);

            if (is_string($content)) {
                expect($content)->not->toContain($otherUser->email, "Found other user email in {$fileName}");
                expect($content)->not->toContain($otherUser->name, "Found other user name in {$fileName}");
            }
        }

        $zip->close();
        unlink($tempZip);
    });

    it('excludes provider_meta from linked accounts export', function () {
        Storage::fake('local');

        // Create a linked account with provider_meta
        LinkedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'google',
            'provider_meta' => ['access_token' => 'secret-value', 'scope' => 'openid'],
        ]);

        Artisan::call('export:user-data', ['user' => $this->user->id]);
        $output = trim(Artisan::output());
        $lines = explode("\n", $output);
        $storedPath = end($lines);

        $linkedAccounts = extractJsonFromZip($storedPath, 'linked-accounts.json');

        expect($linkedAccounts)->not->toBeEmpty();
        $account = $linkedAccounts[0];
        expect($account)->not->toHaveKey('provider_meta', 'provider_meta should be excluded from export');
        expect($account)->toHaveKey('provider');
        expect($account)->toHaveKey('provider_user_id');
    });
});

// ── Signed Download URL ──────────────────────────────

describe('Signed Download URL', function () {
    it('returns ZIP file with valid signed URL', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        // The artisan command outputs the stored path as the last line
        $output = trim(Artisan::output());
        // Extract the path — the command outputs info lines then the path
        $lines = array_filter(explode("\n", $output));
        $exportPath = end($lines);

        // If the path doesn't start with exports/, look for it in the fake disk
        if (! str_starts_with($exportPath, 'exports/')) {
            $files = Storage::disk('local')->files('exports');
            $exportPath = collect($files)->first(fn ($f) => str_contains($f, $this->user->id));
        }

        // Create a resolved ticket with export_path metadata (mirrors production flow)
        $department = Department::factory()->create(['name' => 'Account Support']);
        Ticket::factory()->create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'ticket_type' => 'data_export_request',
            'status' => 'resolved',
            'department_id' => $department->id,
            'metadata' => [
                'export_path' => $exportPath,
            ],
        ]);

        $signedUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
            'token' => ExportDownloadController::deriveFileToken($exportPath),
        ], now()->addDays(7));

        $response = $this->actingAs($this->user)->get($signedUrl);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/zip');
    });

    it('rejects a tampered signature', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $signedUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
            'token' => 'invalid-token',
        ], now()->addDays(7));
        $tamperedUrl = $signedUrl.'&tampered=1';

        $response = $this->actingAs($this->user)->get($tamperedUrl);
        $response->assertStatus(403);
    });

    it('rejects an expired signed URL', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $expiredUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
            'token' => 'any-token',
        ], now()->subDay());

        $response = $this->actingAs($this->user)->get($expiredUrl);
        $response->assertStatus(403);
    });

    it('returns 404 when no export file exists for the user', function () {
        Storage::fake('local');

        $signedUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
            'token' => 'any-token',
        ], now()->addDays(7));

        $response = $this->actingAs($this->user)->get($signedUrl);
        $response->assertStatus(404);
    });

    it('rejects unsigned access to the download route', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $response = $this->actingAs($this->user)->get(route('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
        ]));
        $response->assertStatus(403);
    });

    it('rejects a different authenticated user from downloading someone elses export', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $otherUser = User::factory()->create();

        $signedUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
            'token' => 'any-token',
        ], now()->addDays(7));

        $response = $this->actingAs($otherUser)->get($signedUrl);
        $response->assertStatus(403);
    });
});

// ── ContactSupport data_request ──────────────────────

describe('Contact Support Data Request', function () {
    it('creates a data_export_request ticket type', function () {
        Livewire::actingAs($this->user)
            ->test(ContactSupport::class)
            ->set('subject', 'I want my data')
            ->set('description', 'Please export all my data.')
            ->set('issueType', 'data_request')
            ->call('submitSupport')
            ->assertHasNoErrors();

        $ticket = Ticket::where('ticket_type', 'data_export_request')
            ->where('requester_id', $this->user->id)
            ->first();

        expect($ticket)->not->toBeNull()
            ->and($ticket->ticket_type)->toBe('data_export_request')
            ->and($ticket->department_id)->toBe($this->accountSupportDept->id)
            ->and($ticket->metadata['issue_type'])->toBe('data_request');
    });

    it('applies data-export tag to the ticket', function () {
        Livewire::actingAs($this->user)
            ->test(ContactSupport::class)
            ->set('subject', 'Export please')
            ->set('description', 'My data.')
            ->set('issueType', 'data_request')
            ->call('submitSupport')
            ->assertHasNoErrors();

        $ticket = Ticket::where('ticket_type', 'data_export_request')
            ->where('requester_id', $this->user->id)
            ->first();

        expect($ticket)->not->toBeNull();
        $ticket->load('tags');
        $tagNames = $ticket->tags->pluck('name')->toArray();
        expect($tagNames)->toContain('data-export');
    });
});

// ── Test Helpers ─────────────────────────────────────

function extractZipFileNames(string $storedPath): array
{
    $zipContent = Storage::disk('local')->get($storedPath);
    $tempZip = writeTempZip($zipContent);

    $zip = new ZipArchive;
    test()->assertTrue($zip->open($tempZip), 'Failed to open generated ZIP');

    $fileNames = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $fileNames[] = $zip->getNameIndex($i);
    }

    $zip->close();
    unlink($tempZip);

    return $fileNames;
}

function extractJsonFromZip(string $storedPath, string $fileName): array
{
    $zipContent = Storage::disk('local')->get($storedPath);
    $tempZip = writeTempZip($zipContent);

    $zip = new ZipArchive;
    $zip->open($tempZip);
    $json = $zip->getFromName($fileName);
    $zip->close();
    unlink($tempZip);

    return json_decode($json, true);
}

function writeTempZip(string $content): string
{
    $tempZip = tempnam(sys_get_temp_dir(), 'export_test_').'.zip';
    file_put_contents($tempZip, $content);

    return $tempZip;
}
