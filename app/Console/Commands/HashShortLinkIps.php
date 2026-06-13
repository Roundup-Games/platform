<?php

namespace App\Console\Commands;

use App\Models\ShortLinkHit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-time command to hash raw IP addresses in short_link_hits.
 *
 * Post-deployment cleanup for records created before the PII hashing fix.
 * Safe to re-run — hashes are idempotent when the input is already a 64-char hex string.
 */
class HashShortLinkIps extends Command
{
    protected $signature = 'short-links:hash-ips
                            {--dry-run : Show count without making changes}';

    protected $description = 'Hash raw IP addresses in short_link_hits for PII compliance';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Identify rows with raw IPs: less than 64 chars and not null.
        // SHA256 hex output is always 64 chars; raw IPv4 is 7-15 chars, IPv6 up to 45.
        $query = ShortLinkHit::whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->whereRaw('LENGTH(ip_address) < 64');

        $count = $dryRun ? $query->count() : 0;

        if ($dryRun) {
            $this->info("Would hash {$count} raw IP address(es).");

            return self::SUCCESS;
        }

        $hashed = 0;
        $query->chunkById(500, function ($hits) use (&$hashed) {
            foreach ($hits as $hit) {
                $ip = $hit->ip_address;
                $key = config('app.key');
                $hit->ip_address = is_string($ip) && is_string($key) ? hash('sha256', $ip.$key) : $ip;
                $hit->save();
                $hashed++;
            }
        });

        $this->info("Hashed {$hashed} raw IP address(es).");

        Log::channel('daily')->info('short-links:hash-ips completed', [
            'hashed_count' => $hashed,
        ]);

        return self::SUCCESS;
    }
}
