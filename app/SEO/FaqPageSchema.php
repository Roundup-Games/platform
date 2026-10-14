<?php

namespace App\SEO;

use Illuminate\Support\Collection;
use RalphJSmit\Laravel\SEO\Schema\CustomSchemaFluent;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * FAQPage JSON-LD schema for the /faq page.
 *
 * Mirrors AlgorithmsSchema: frames each question/answer pair for rich
 * search results. Unlike AlgorithmsSchema's hand-maintained prose, the
 * questions and answers are read from the active locale's lang/faq.php
 * keys, so the structured data can never drift from the rendered page —
 * both surfaces share one source of copy. The action_* link labels are
 * deliberately excluded: JSON-LD answers are plain text, links live in
 * the Blade template.
 */
class FaqPageSchema extends CustomSchemaFluent
{
    public string $type = 'FAQPage';

    public Collection $questions; // @phpstan-ignore missingType.generics

    /**
     * Ordered question/answer key pairs, mirroring the page's section order.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const QA_KEYS = [
        ['faq.content_q_system_vs_game', 'faq.content_a_system_vs_game'],
        ['faq.content_q_event_vs_game', 'faq.content_a_event_vs_game'],
        ['faq.content_q_bench_vs_waitlist', 'faq.content_a_bench_vs_waitlist'],
        ['faq.content_q_reliability', 'faq.content_a_reliability'],
        ['faq.content_q_free_to_use', 'faq.content_a_free_to_use'],
        ['faq.content_q_host_first', 'faq.content_a_host_first'],
        ['faq.content_q_create_event', 'faq.content_a_create_event'],
        ['faq.content_q_venue_verified', 'faq.content_a_venue_verified'],
        ['faq.content_q_data_rights', 'faq.content_a_data_rights'],
    ];

    public function initializeMarkup(SEOData $SEOData): void
    {
        $this->questions = collect(self::QA_KEYS)
            ->map(fn (array $pair): array => [
                '@type' => 'Question',
                'name' => __($pair[0]),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => __($pair[1]),
                ],
            ])
            ->values();
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
