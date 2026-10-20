<?php

use App\Enums\JournalEntryKind;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;

use function Pest\Laravel\get;

// M071/S05-T04 — The public "Campaign so far" block (R082/D222): the
// GM-published recruitment summary renders as a marked section after the
// description on the public campaign page, escaped via nl2br(e()), null-safe
// (no summary = no markup), and — the whole point of this suite — journal
// content NEVER renders publicly in either the no-block or with-block state
// (D188 journal-never-public pin). Plus the SEO description guard and the
// access-matrix negative (an applied block adds no public bypass).

beforeEach(function () {
    app()->setLocale('en');
});

/**
 * A public campaign (the only visibility the public detail page serves).
 *
 * @param  array<string, mixed>  $attributes
 */
function publicDetailCampaign(array $attributes = []): Campaign
{
    return Campaign::factory()->create([
        'visibility' => 'public',
        ...$attributes,
    ]);
}

// ═══════════════════════════════════════════════════════════

describe('applied block rendering', function () {
    it('renders heading, body, and refreshed line when a summary is applied', function () {
        $campaign = publicDetailCampaign([
            'recruitment_summary' => "The fellowship left Bree in high spirits.\nSeats are open for brave new members.",
            'recruitment_summary_completed_sessions' => 6,
            'recruitment_summary_refreshed_at' => now(),
        ]);

        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee(__('campaigns.heading_campaign_so_far', [], 'en'))
            ->assertSee('The fellowship left Bree in high spirits.')
            ->assertSee('Seats are open for brave new members.')
            ->assertSee(__('campaigns.content_last_refreshed_after_session', ['count' => 6], 'en'));
    });

    it('renders no block markup when no summary is applied', function () {
        $campaign = publicDetailCampaign();

        // Null-safe by construction: no summary means not even the heading
        // or refreshed line is emitted — the page stays byte-identical to
        // the pre-R082 public detail for untouched campaigns.
        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertDontSee(__('campaigns.heading_campaign_so_far', [], 'en'))
            ->assertDontSee(__('campaigns.content_last_refreshed_after_session', ['count' => 6], 'en'));
    });

    it('escapes HTML in the summary body (nl2br(e()) contract)', function () {
        $campaign = publicDetailCampaign([
            'recruitment_summary' => '<script>alert(1)</script>',
            'recruitment_summary_completed_sessions' => 1,
            'recruitment_summary_refreshed_at' => now(),
        ]);

        // Raw tag absent, entity present — pins the escaping contract
        // against a future switch to unescaped {!! !!} output.
        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
    });
});

// ═══════════════════════════════════════════════════════════

describe('journal leak regression (R082/D188)', function () {
    it('renders no journal content publicly when no block is applied', function () {
        $campaign = publicDetailCampaign();

        // One entry per kind, each carrying a unique marker in its body.
        CampaignJournalEntry::factory()->create([
            'campaign_id' => $campaign->id,
            'kind' => JournalEntryKind::Recap,
            'body' => 'The dragon fell at last. RECAP-LEAK-7f3a',
        ]);
        CampaignJournalEntry::factory()->create([
            'campaign_id' => $campaign->id,
            'kind' => JournalEntryKind::PrepNote,
            'body' => 'Secret twist: the baron is the villain. PREPNOTE-LEAK-9b1c',
        ]);
        CampaignJournalEntry::factory()->create([
            'campaign_id' => $campaign->id,
            'kind' => JournalEntryKind::InCharacter,
            'body' => 'I have been the traitor all along. INCHARACTER-LEAK-4d2e',
        ]);

        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertDontSee('RECAP-LEAK-7f3a')
            ->assertDontSee('PREPNOTE-LEAK-9b1c')
            ->assertDontSee('INCHARACTER-LEAK-4d2e');
    });

    it('keeps journal bodies out of the page even when a block is applied', function () {
        $campaign = publicDetailCampaign();

        CampaignJournalEntry::factory()->create([
            'campaign_id' => $campaign->id,
            'kind' => JournalEntryKind::Recap,
            'body' => 'The dragon fell at last. RECAP-LEAK-7f3a',
        ]);
        CampaignJournalEntry::factory()->create([
            'campaign_id' => $campaign->id,
            'kind' => JournalEntryKind::PrepNote,
            'body' => 'Secret twist: the baron is the villain. PREPNOTE-LEAK-9b1c',
        ]);
        CampaignJournalEntry::factory()->create([
            'campaign_id' => $campaign->id,
            'kind' => JournalEntryKind::InCharacter,
            'body' => 'I have been the traitor all along. INCHARACTER-LEAK-4d2e',
        ]);

        $campaign->update([
            'recruitment_summary' => 'A safe, GM-approved summary. APPLIED-BLOCK-a5c9',
            'recruitment_summary_completed_sessions' => 2,
            'recruitment_summary_refreshed_at' => now(),
        ]);

        // The applied block renders, but NONE of the journal bodies leak
        // through it — a prep-note body must never render publicly in
        // either state, and the block is never auto-fed from the journal.
        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('APPLIED-BLOCK-a5c9')
            ->assertDontSee('RECAP-LEAK-7f3a')
            ->assertDontSee('PREPNOTE-LEAK-9b1c')
            ->assertDontSee('INCHARACTER-LEAK-4d2e');
    });
});

// ═══════════════════════════════════════════════════════════

describe('SEO guard', function () {
    it('keeps the summary out of the SEO description while it renders in the block', function () {
        $campaign = publicDetailCampaign([
            'description' => ['en' => 'A welcoming tavern campaign for new adventurers.'],
            'recruitment_summary' => 'SeoGuardMarker zq71 the summary must not reach og:description.',
            'recruitment_summary_completed_sessions' => 3,
            'recruitment_summary_refreshed_at' => now(),
        ]);

        // og:description derives from the description column only —
        // guards against future "helpful" fixes widening the SEO source.
        $seoDescription = $campaign->getDynamicSEOData()->description;
        expect($seoDescription)->toContain('welcoming tavern campaign');
        expect($seoDescription)->not->toContain('SeoGuardMarker');

        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('SeoGuardMarker');
    });
});

// ═══════════════════════════════════════════════════════════

describe('access matrix', function () {
    it('still denies a private campaign with an applied block to guests', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => 'private',
            'recruitment_summary' => 'This summary must never load for guests.',
            'recruitment_summary_completed_sessions' => 1,
            'recruitment_summary_refreshed_at' => now(),
        ]);

        // CampaignPolicy::view unchanged: the block adds no new public
        // bypass — private stays 403 even with a published summary.
        get(route('campaigns.detail', $campaign->id))->assertForbidden();
    });
});
