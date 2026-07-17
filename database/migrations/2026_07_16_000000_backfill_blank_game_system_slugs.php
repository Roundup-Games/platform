<?php

use App\Models\GameSystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Assign a routable slug to any game system left with a blank slug.
     *
     * A blank slug makes route('game-systems.show', $slug) throw
     * UrlGenerationException, which broke the related-systems partial whenever
     * such a record surfaced as a base game or expansion.
     */
    public function up(): void
    {
        GameSystem::query()
            ->whereRaw("TRIM(COALESCE(slug, '')) = ''")
            ->get()
            ->each(function (GameSystem $system): void {
                $name = $system->getTranslation('name', 'en');
                $slug = Str::slug(is_string($name) ? $name : '');

                if ($slug === '') {
                    $slug = 'game-system-'.$system->getKey();
                }

                $system->slug = $this->uniqueSlug($slug, (string) $system->getKey());
                $system->saveQuietly();
            });
    }

    public function down(): void
    {
        // Irreversible data backfill — restoring blank slugs would reintroduce the defect.
    }

    /**
     * Ensure the slug does not collide with another game system's slug.
     */
    private function uniqueSlug(string $base, string $ignoreId): string
    {
        $candidate = $base;
        $suffix = 2;

        while (GameSystem::query()
            ->where('slug', $candidate)
            ->whereKeyNot($ignoreId)
            ->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
};
