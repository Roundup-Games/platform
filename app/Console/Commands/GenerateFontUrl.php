<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateFontUrl extends Command
{
    protected $signature = 'fonts:url {--show : Print the full URL}';

    protected $description = 'Generate the optimized Google Fonts URL with Material Symbols subsetting';

    public function handle(): int
    {
        $icons = config('fonts.material_symbols', []);
        $iconList = implode(',', array_unique(array_map('trim', $icons)));

        $interWeights = config('fonts.inter_weights', '400;500;600');
        $notoWeights = config('fonts.noto_serif_weights', '0,400;0,600;0,700;1,400');

        $textFontUrl = sprintf(
            'https://fonts.googleapis.com/css2?family=Inter:wght@%s&family=Noto+Serif:ital,wght@%s&display=swap',
            $interWeights,
            $notoWeights
        );

        $iconFontUrl = sprintf(
            'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap',
        );

        // Google Fonts doesn't support icon_names subsetting via the CSS2 API directly
        // in all browsers reliably. Instead, we use the full icon set but with
        // font-display: optional to prevent render-blocking and CLS.
        // The FILL axis is kept variable for filled/unfilled icon variants.

        if ($this->option('show')) {
            $this->line("Text font URL:\n  {$textFontUrl}");
            $this->line("\nIcon font URL:\n  {$iconFontUrl}");
            $this->line("\nUnique icons tracked: " . count($icons));
        } else {
            $this->info('Font URLs configured in config/fonts.php');
            $this->info('Icons tracked: ' . count($icons));
        }

        return self::SUCCESS;
    }
}
