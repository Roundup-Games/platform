<?php

// Venue *page* keys (M053/S02). Kept separate from venues.php (the picker)
// so the public detail page owns its own surface. T03 extends this file.
return [
    // Back / nav
    'action_back_to_discover' => 'Back to Discover',

    // Link affordance (M053/S02/T03 <x-venue-link>). The visible text is the
    // venue name itself; this labels the link affordance for assistive tech.
    'action_view_venue' => 'View venue: :name',

    // Header
    'label_venue_type' => 'Venue type',
    'action_visit_website' => 'Visit website',
    'label_managed_by' => 'Managed by',

    // Activity sections
    'heading_upcoming_sessions' => 'Upcoming Sessions',
    'heading_past_sessions' => 'Past Sessions',
    'heading_active_campaigns' => 'Active Campaigns',
    'heading_completed_campaigns' => 'Past Campaigns',

    // Empty state — shown once when the venue has no activity at all.
    // Per-section "No X" boxes are hidden; only this single fallback renders.
    'content_no_activity_yet' => 'No sessions listed at this venue yet — check back soon.',

    // Reviews (S03 hook)
    'reviews_heading' => 'Reviews',
    'content_reviews_soon' => 'Reviews for this venue are coming soon.',

    // Reviews surface (M053/S03/T04 VenueReviews component)
    'content_no_reviews' => 'No reviews for this venue yet.',
    'reviews_count' => ':count review|:count reviews',
    'label_your_rating' => 'Your Rating',
    'placeholder_venue_review' => 'Tell us about your experience at this venue (optional)',
    'action_submit_venue_review' => 'Leave a Review',
    'content_not_eligible' => 'You can review this venue after attending a completed session here.',
    'flash_venue_review_submitted' => 'Your venue review has been submitted. Thank you!',
    'validation_venue_rating_required' => 'Please select a rating.',
    'validation_venue_body_max' => 'Review text cannot exceed 2000 characters.',

    // ── Claim a venue (M053/S04/T04) ─────────────────────────────────────────
    'heading_claim_venue' => 'Claim this Venue',
    'content_claim_subtitle' => 'Tell us why you should manage ":name" and our team will review your claim.',
    'field_justification' => 'Why should you manage this venue?',
    'placeholder_justification' => 'e.g. I am the owner / operator of this venue and want to keep the page up to date.',
    'heading_optional_proof' => 'Optional proof',
    'field_website' => 'Website',
    'placeholder_claim_website' => 'https://example.com',
    'field_contact_email' => 'Contact email',
    'hint_contact_email' => 'Only used by our team to verify your affiliation with this venue.',
    'action_submit_claim' => 'Submit Claim',
    'action_claim_venue' => 'Claim this venue',
    'action_back_to_venue' => 'Back to venue',
    'content_claim_success' => 'Your venue claim has been submitted! Our team will review it shortly.',
    'content_claim_reference' => 'Reference: :reference',
    'error_claim_duplicate' => 'You already have a pending claim for this venue.',
    'error_claim_rate_limit' => 'You\'ve submitted too many claims. Please try again in :hours hours.',
    'error_claim_submission_failed' => 'Failed to submit venue claim. Please try again later.',
];
