<?php

namespace App\SEO;

use Illuminate\Support\Collection;
use RalphJSmit\Laravel\SEO\Schema\CustomSchemaFluent;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * FAQPage JSON-LD schema for the algorithms transparency page.
 *
 * Frames each algorithm section as a question/answer pair for rich
 * search results and improved LLM discoverability.
 */
class AlgorithmsSchema extends CustomSchemaFluent
{
    public string $type = 'FAQPage';

    public Collection $questions; // @phpstan-ignore missingType.generics

    public function initializeMarkup(SEOData $SEOData): void
    {
        $this->questions = collect([
            [
                '@type' => 'Question',
                'name' => 'How does the Player Reliability Score work?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'The Player Reliability Score is an attendance-based metric calculated as (weighted_sum / game_count) × 100, clamped to 0–100%. Attended sessions add +1.0, late cancellations subtract −0.3 for players (−1.2 for hosts), and no-shows subtract −1.0 for players (−1.5 for hosts). Excused and early cancellations carry zero weight. Scores are fully recomputed on every attendance change to guarantee correctness.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How are GM Ratings and Reviews calculated?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'GM Ratings use a simple average (AVG) of all published reviews on a 1–5 scale, defaulting to 0 when no reviews exist. Only reviews with "published" status are included — pending, reported, or removed reviews are excluded entirely. Aggregates are recomputed on every review change to keep the displayed score in sync.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How does People Discovery suggest nearby players?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'People Discovery uses a four-phase pipeline: (1) geohash tile expansion starting at ~20km, expanding to ~100km and ~500km as needed, excluding blocked users and existing follows; (2) bulk preference loading for game systems, vibes, teams, and follows; (3) scoring with taste similarity (Jaccard on game systems + vibes, weight 0.7) and social overlap (team + follow overlap, weight 0.3); (4) paginated results cached per tile for 5 minutes.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How are game sessions recommended to me?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Session Recommendations use a two-query approach. The boosted query matches sessions sharing your favorite game systems AND favorite vibes — the strongest matches. The fallback query matches by game systems regardless of vibes. Favorited base games automatically include expansions as "implied favorites." Explicit avoid preferences always override favorites. Results are deduplicated and capped at 12 total recommendations.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How does the Proximity Engine find nearby sessions?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'The Proximity Engine uses a two-phase approach: first, a fast bounding-box pre-filter using a composite latitude/longitude B-tree index to eliminate distant rows; second, precise Haversine distance calculation filtering to the exact search radius. Results are cached per geohash tile (~2.4km × 4.9km at 5-character precision) with a 15-minute TTL.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How are trending and popular sessions determined?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Trending sessions are identified by geographic scope (geohash-4 tile, ~20km × 20km), limited to public sessions in the next 14 days, sorted by participant count and creation date. The top 5 per tile are cached with a 10-minute TTL. This ensures freshness without expensive real-time queries.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'What is the Platform Score and how is it calculated?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'The Platform Score ranks games and campaigns using a weighted formula: score = (favorites × w₁) + (total_games × w₂) + (campaigns × w₃) + (active_games × w₄). Weights differ by game type — board games weight active games higher (20 vs 10 for TTRPGs), while TTRPGs weight campaigns higher (15 vs 5 for board games). This reflects the different engagement patterns of each game type.',
                ],
            ],
        ]);
    }

    /**
     * @return Collection<int, mixed>
     */
    public function generateInner(): Collection
    {
        $result = collect([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $this->questions,
        ])->pipeThrough($this->markupTransformers);

        return $result instanceof Collection ? $result : collect();
    }
}
