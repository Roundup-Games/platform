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
                    'text' => 'GM Ratings use a simple average (AVG) of all published reviews on a 1–5 scale; the stored average is null when no published reviews exist. Only reviews with "published" status are included — reported or hidden reviews are excluded entirely. Aggregates are recomputed when a review is created, deleted, or changes status (for example when a reported review is hidden or restored), keeping the displayed score in sync with the current published set.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How does People Discovery suggest nearby players?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'People Discovery uses a five-phase pipeline: (1) a single combined geohash query across the 4-char (~20km) and 3-char (~100km) tiles, excluding blocked users and existing follows, capped at 50 candidates; (2) a taste-based supplement that pulls in candidates sharing your favorite game systems even outside the geographic radius; (3) SQL-first scoring, where one JOIN query computes all overlap counts (shared game systems, vibes, teams, and follows) server-side; (4) scoring with taste similarity (Jaccard on game systems + vibes, weight 0.7) and social overlap (team + follow overlap, weight 0.3), re-weighted for hidden profile fields; (5) results precomputed by a background job and cached per viewer per tile for 5 minutes, paginated at 12 per page.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How are game sessions recommended to me?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Session Recommendations use a two-query approach. The boosted query matches sessions sharing your favorite game systems AND favorite vibes — the strongest matches. The fallback query matches by game systems regardless of vibes. Favorited base games automatically include expansions as "implied favorites," and explicit avoid preferences always override favorites. Results are deduplicated and capped at 12 total recommendations. A Gathering (a lighter, multi-system session like a board-game night) appears in recommendations whenever any of its offered systems matches your favorites.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How is attendance resolved after a game ends?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Attendance Resolution is a consensus system that reconciles potentially conflicting host and participant reports into a single resolved status, which then feeds the Player Reliability Score. It has a deliberate default-to-attended bias: an absence must be proven by a majority, never assumed. At least half of the other participants must file a report (participation gate); the host can excuse a player (host-excused override); and more than half of the weighted votes must be "no-show" for an absence to be recorded. Anything else — a tie, no reports, or a solo game — resolves as Attended. Resolution happens via early consensus (everyone reported), timeout (the game auto-completes after the window), or manual admin action. Reports are weighted so low-reliability reporters carry less influence.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How does the platform decide what location and distance to show me?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'A single service governs every address and distance you see. Location Disclosure graduates how much is revealed across four rungs — Exact (full address), City (locality only), Area ("in your area"), and None (blocked viewers). Verified commercial venues (cafés, game stores, libraries) show their exact address to everyone; private locations graduate by your relationship to the host (owner or approved participant → Exact, friend or teammate → City, stranger or guest → Area, blocked → None). Distances are precise only for verified public venues — every other location is grid-snapped to the nearest 5 km to prevent triangulation. The service is fail-closed: when anything cannot be determined, it reveals the least possible, never more.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'How does the Proximity Engine find nearby sessions?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'The Proximity Engine uses a two-phase approach: first, a fast bounding-box pre-filter using a composite latitude/longitude B-tree index to eliminate distant rows; second, precise Haversine distance calculation filtering to the exact search radius. Results are cached per geohash tile (~4.9km × 4.9km at 5-character precision) with a 15-minute TTL.',
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
