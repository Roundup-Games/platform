<?php

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

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->accountSupportDept = Department::factory()->create(['name' => 'Account Support']);
    Tag::factory()->create(['name' => 'data-export', 'color' => '#10B981']);
    Tag::factory()->create(['name' => 'account-recovery', 'color' => '#2563EB']);
});

// ── Profile requestExport() ──────────────────────────

describe('Profile Data Export Request', function () {
    it('creates a data_export_request ticket from profile', function () {
        $component = \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Profile\Show::class);

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
            ->and($ticket->metadata['source'])->toBe('profile_settings')
            ->and($ticket->metadata['user_id'])->toBe($this->user->id);
    });

    it('prevents duplicate open export requests', function () {
        $component = \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Profile\Show::class);
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
        $component = \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Profile\Show::class);
        $component->call('requestExport');

        // Resolve the ticket
        $ticket = Ticket::where('ticket_type', 'data_export_request')
            ->where('requester_id', $this->user->id)
            ->first();
        $ticket->update(['status' => TicketStatus::Resolved->value]);

        // Reload component — should see no pending request now
        $component = \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Profile\Show::class);
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

        $zip = new ZipArchive();
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
});

// ── Signed Download URL ──────────────────────────────

describe('Signed Download URL', function () {
    it('returns ZIP file with valid signed URL', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $signedUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
        ], now()->addDays(7));

        $response = $this->get($signedUrl);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/zip');
    });

    it('rejects a tampered signature', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $signedUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
        ], now()->addDays(7));
        $tamperedUrl = $signedUrl . '&tampered=1';

        $response = $this->get($tamperedUrl);
        $response->assertStatus(403);
    });

    it('rejects an expired signed URL', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $expiredUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
        ], now()->subDay());

        $response = $this->get($expiredUrl);
        $response->assertStatus(403);
    });

    it('returns 404 when no export file exists for the user', function () {
        Storage::fake('local');

        $signedUrl = URL::signedRoute('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
        ], now()->addDays(7));

        $response = $this->get($signedUrl);
        $response->assertStatus(404);
    });

    it('rejects unsigned access to the download route', function () {
        Storage::fake('local');
        Artisan::call('export:user-data', ['user' => $this->user->id]);

        $response = $this->get(route('export.download', [
            'locale' => 'en',
            'user' => $this->user->id,
        ]));
        $response->assertStatus(403);
    });
});

// ── ContactSupport data_request ──────────────────────

describe('Contact Support Data Request', function () {
    it('creates a data_export_request ticket type', function () {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Support\ContactSupport::class)
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
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Support\ContactSupport::class)
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

    $zip = new ZipArchive();
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

    $zip = new ZipArchive();
    $zip->open($tempZip);
    $json = $zip->getFromName($fileName);
    $zip->close();
    unlink($tempZip);

    return json_decode($json, true);
}

function writeTempZip(string $content): string
{
    $tempZip = tempnam(sys_get_temp_dir(), 'export_test_') . '.zip';
    file_put_contents($tempZip, $content);

    return $tempZip;
}
