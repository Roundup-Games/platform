<?php

namespace App\Console\Commands\Seo;

use App\Models\GameSystem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

/**
 * Reconciles BGG duplicate catalog rows (SEO audit 2026-10-15).
 *
 * BGG lists distinct bgg_id entries for the same physical game; the sync
 * imports each as its own row with an id-suffixed slug on collision. This
 * command groups rows by identical (case-normalized) English name and marks
 * every non-primary row as a duplicate of one canonical row, so their pages
 * canonicalize to the primary URL and drop out of the sitemap.
 *
 * Primary selection: the row whose slug equals the name's base slug (the
 * un-suffixed winner of the original collision); among equals, prefer a row
 * without a canonical of its own, then the lowest id. Existing assignments
 * are only rewritten when they disagree with the computed primary — the
 * command is idempotent and safe to re-run.
 *
 * DRY-RUN BY DEFAULT: pass --execute to write assignments. Model saves ride
 * the SeoModelObserver, so the game-systems sitemap cache flushes itself.
 */
#[Signature('seo:dedupe-game-systems {--execute : Write assignments (default: dry-run)}')]
#[Description('Mark BGG duplicate game-system rows as duplicates of one canonical row')]
class SeoDedupeGameSystemsCommand extends Command
{
    public function handle(): int
    {
        $groups = GameSystem::query()
            ->get(['id', 'slug', 'name', 'bgg_id', 'canonical_game_system_id'])
            ->groupBy(function (GameSystem $system): string {
                $name = $system->getTranslation('name', 'en');

                return mb_strtolower(trim(is_string($name) ? $name : ''));
            })
            ->filter(fn (Collection $group): bool => $group->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No duplicate game-system groups found.');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');
        $mode = $execute ? 'EXECUTE' : 'DRY-RUN (pass --execute to write)';
        $changed = 0;

        foreach ($groups as $name => $group) {
            $primary = $this->resolvePrimary($group, (string) $name);

            $this->components->twoColumnDetail($name, "primary: {$primary->slug}");

            /** @var GameSystem $system */
            foreach ($group->reject(fn (GameSystem $system): bool => $system->is($primary)) as $system) {
                $current = $system->canonical_game_system_id;

                if ($current === $primary->id) {
                    $this->components->twoColumnDetail("  {$system->slug}", 'already canonical');

                    continue;
                }

                $this->components->twoColumnDetail(
                    "  {$system->slug}",
                    'canonical → '.$primary->slug.($current !== null ? ' (was '.$current.')' : ''),
                );

                if ($execute) {
                    $system->canonical_game_system_id = $primary->id;
                    $system->save();
                }

                $changed++;
            }
        }

        $this->newLine();
        $this->info(sprintf('[%s] %d assignment(s) %s across %d group(s).', $mode, $changed, $execute ? 'written' : 'pending', $groups->count()));

        return self::SUCCESS;
    }

    /**
     * Pick the canonical row for one duplicate group: the holder of the
     * base (un-suffixed) slug, preferring rows without a canonical of
     * their own, then the lowest id as a stable tiebreaker.
     *
     * @param  Collection<int, GameSystem>  $group
     */
    private function resolvePrimary(Collection $group, string $normalizedName): GameSystem
    {
        $baseSlug = Str::slug($normalizedName);

        return $group
            ->sortBy(fn (GameSystem $system): array => [
                $system->slug !== $baseSlug,            // base-slug holder wins
                $system->canonical_game_system_id !== null, // canonical-free rows next
                $system->id,                            // stable tiebreaker
            ])
            ->first()
            ?? throw new LogicException('Duplicate group cannot be empty (filtered to count > 1).');
    }
}
