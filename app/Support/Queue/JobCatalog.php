<?php

namespace App\Support\Queue;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Static inventory of the job fleet (M068, 2026-10-17).
 *
 * Horizon shows what is running or recently ran (60-minute window); this
 * catalog shows what is EXPECTED — every job class, its queue, execution
 * properties, and how it enters the system. Reflection is deterministic:
 * the catalog can never drift from the codebase, only lag a deploy.
 *
 * Honest limitations (shown in the admin page):
 *  - Queue attribution reads the constructor's onQueue('...') call from
 *    source. Jobs without one run on the connection default ('default').
 *  - "Scheduled" means the class is referenced by routes/console.php (the
 *    schedule or an artisan command it calls); everything else is
 *    event- or action-dispatched.
 */
final class JobCatalog
{
    /**
     * @return list<array{
     *     class: string,
     *     short: string,
     *     queue: string,
     *     queue_inferred: bool,
     *     tries: int|null,
     *     timeout: int|null,
     *     backoff: array<int, int>|int|null,
     *     unique: bool,
     *     encrypted: bool,
     *     scheduled: bool,
     * }>
     */
    public static function jobs(): array
    {
        return Cache::remember('admin:queue-catalog', now()->addMinutes(5), fn (): array => self::build());
    }

    /**
     * @return list<array{
     *     class: string, short: string, queue: string, queue_inferred: bool,
     *     tries: int|null, timeout: int|null, backoff: array<int, int>|int|null,
     *     unique: bool, encrypted: bool, scheduled: bool,
     * }>
     */
    private static function build(): array
    {
        $scheduleSource = file_get_contents(base_path('routes/console.php'));
        $scheduleSource = $scheduleSource === false ? '' : $scheduleSource;

        $jobs = [];

        // Recursive discovery: a namespaced Jobs/<Subdir>/ class is a
        // real job too, and a flat glob would silently drop it from
        // the admin fleet view (and from this catalog's consumers).
        $finder = Finder::create()
            ->files()
            ->in(app_path('Jobs'))
            ->name('*.php')
            ->sortByName();

        foreach ($finder as $file) {
            // Suffix-only strip: a path containing '.php' anywhere but
            // the end (a Jobs/Legacy.php/ directory name) must not
            // corrupt the derived class name. preg_replace null (PCRE
            // error) falls back to the raw path — class_exists filters
            // anything unresolvable anyway.
            $relative = preg_replace('/\.php$/', '', $file->getRelativePathname()) ?? $file->getRelativePathname();
            $short = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            $class = 'App\Jobs\\'.$short;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $defaults = $reflection->getDefaultProperties();
            $realPath = $file->getRealPath();
            $source = file_get_contents($realPath === false ? '' : $realPath);
            $source = $source === false ? '' : $source;

            [$queue, $queueInferred] = self::resolveQueue($source);

            $jobs[] = [
                'class' => $class,
                'short' => $short,
                'queue' => $queue,
                'queue_inferred' => $queueInferred,
                'tries' => is_numeric($defaults['tries'] ?? null) ? (int) $defaults['tries'] : null,
                'timeout' => is_numeric($defaults['timeout'] ?? null) ? (int) $defaults['timeout'] : null,
                'backoff' => self::normalizeBackoff($defaults['backoff'] ?? null),
                'unique' => $reflection->implementsInterface(ShouldBeUnique::class),
                'encrypted' => $reflection->implementsInterface(ShouldBeEncrypted::class),
                'scheduled' => str_contains($scheduleSource, $class) || str_contains($scheduleSource, "\\{$short}") || str_contains($scheduleSource, " {$short}::"),
            ];
        }

        usort($jobs, fn (array $a, array $b): int => [$a['queue'], $a['short']] <=> [$b['queue'], $b['short']]);

        return $jobs;
    }

    /**
     * The queue a job runs on, from its constructor's onQueue('...') call.
     * A missing call means the connection default queue — reported as
     * 'default' but marked inferred so the page can say so.
     *
     * @return array{0: string, 1: bool}
     */
    private static function resolveQueue(string $source): array
    {
        if (preg_match("/(?:->onQueue\(\s*|\$this->queue\s*=\s*)['\"]([^'\"]+)['\"]/", $source, $m) === 1) {
            return [$m[1], false];
        }

        return ['default', true];
    }

    /**
     * Reflection default properties are untyped (array<string, mixed>);
     * narrow backoff to the shapes the property can legally hold.
     *
     * @return array<int, int>|int|null
     */
    private static function normalizeBackoff(mixed $backoff): array|int|null
    {
        if (is_int($backoff)) {
            return $backoff;
        }

        if (is_array($backoff)) {
            return array_values(array_map(
                static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
                $backoff,
            ));
        }

        return null;
    }
}
