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
    // Operational parameters (M056/S05/T02) — admin-curated on the venue
    // manager's behalf; rendered on the public venue page when populated.
    'heading_operational_parameters' => 'Operational Parameters',
    'label_overlap_guidance' => 'Overlap guidance',
    'label_fee_display' => 'Fee display',
    'label_house_rules' => 'House rules',
    // Activity sections
    'heading_upcoming_sessions' => 'Upcoming Sessions',
    'heading_past_sessions' => 'Past Sessions',
    'heading_active_campaigns' => 'Active Campaigns',
    'heading_completed_campaigns' => 'Past Campaigns',
    // Empty state — shown once when the venue has no activity at all.
    // Per-section "No X" boxes are hidden; only this single fallback renders.
    'content_no_activity_yet' => 'No sessions listed at this venue yet — check back soon.',
    // Reviews (S03 hook)
    'heading_reviews' => 'Reviews',
    'content_reviews_soon' => 'Reviews for this venue are coming soon.',
    // Reviews surface (M053/S03/T04 VenueReviews component)
    'content_no_reviews' => 'No reviews for this venue yet.',
    'label_reviews_count' => ':count review|:count reviews',
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
    // ── Venue types (internationalized via VenueType::label()) ───────────────
    'type_cafe' => 'Café',
    'type_flgs' => 'FLGS (Friendly Local Game Store)',
    'type_library' => 'Library',
    'type_community_center' => 'Community Center',
    'type_convention' => 'Convention / Convention Center',
    'type_bar' => 'Bar / Pub',
    'type_other' => 'Other',
    // ── Venue directory (/{locale}/venues) ───────────────────────────────────
    'nav_venue_directory' => 'Venue Directory',
    'heading_directory' => 'Find a place to play',
    'content_directory_subtitle' => 'Browse cafés, game stores, and community spaces hosting public sessions near you.',
    'seo_directory_title' => 'Venue Directory — Board Game & TTRPG Venues',
    'seo_directory_description' => 'Browse verified cafés, friendly local game stores, and community spaces hosting public board game and tabletop sessions near you.',
    'placeholder_directory_search' => 'Search by name, city, or address…',
    'field_directory_venue_type' => 'Venue type',
    'field_directory_sort' => 'Sort by',
    'field_directory_min_rating' => 'Min rating',
    'label_directory_any_rating' => 'Any rating',
    'sort_directory_nearest' => 'Nearest first',
    'sort_directory_active' => 'Most active',
    'sort_directory_rating' => 'Highest rated',
    'sort_directory_newest' => 'Newest',
    'filter_directory_has_upcoming' => 'Has upcoming sessions',
    'filter_directory_managed' => 'Operated venue',
    'action_directory_clear_filters' => 'Clear filters',
    'hint_directory_nearest_needs_location' => 'Share your location to sort by distance.',
    'action_directory_load_more' => 'Load more',
    'content_directory_showing_of' => 'Showing :shown of :total',
    'content_directory_upcoming_sessions' => ':count upcoming session|:count upcoming sessions',
    'content_directory_no_upcoming' => 'No upcoming sessions',
    'label_directory_verified' => 'Verified venue',
    'label_directory_managed_badge' => 'Operated',
    'action_directory_use_my_location' => 'Use my location',
    'content_directory_showing_near_you' => 'Showing venues near you',
    'action_directory_clear_location' => 'Clear location',
    'empty_directory_title' => 'No venues match your search',
    'empty_directory_body' => "Try adjusting your filters, or if you know a great place to play that isn't listed yet, propose it and we'll add it to the directory.",
    'action_directory_cta_propose' => 'Propose a venue',
    'action_directory_cta_sign_up_propose' => 'Sign up to propose a venue',
    'heading_directory_footer_cta' => 'Know a place that should be here?',
    'content_directory_footer_cta' => 'Propose a venue and help more players find a seat at the table.',
    'heading_directory_portal_card' => 'Browse venues',
    'content_directory_portal_card' => 'Find cafés, game stores, and community spaces hosting public sessions near you.',
];
