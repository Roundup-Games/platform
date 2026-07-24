<?php

return [
    // Venue *page* keys (M053/S02). Kept separate from venues.php (the picker)
    // so the public detail page owns its own surface. T03 extends this file.
    // Back / nav
    'action_back_to_discover' => '',
    // Link affordance (M053/S02/T03 <x-venue-link>). The visible text is the
    // venue name itself; this labels the link affordance for assistive tech.
    'action_view_venue' => '',
    // Header
    'label_venue_type' => '',
    'action_visit_website' => '',
    'label_managed_by' => '',
    // Operational parameters (M056/S05/T02) — admin-curated on the venue
    // manager's behalf; rendered on the public venue page when populated.
    'heading_operational_parameters' => '',
    'label_overlap_guidance' => '',
    'label_fee_display' => '',
    'label_house_rules' => '',
    // Activity sections
    'heading_upcoming_sessions' => '',
    'heading_past_sessions' => '',
    'heading_active_campaigns' => '',
    'heading_completed_campaigns' => '',
    // Empty state — shown once when the venue has no activity at all.
    // Per-section "No X" boxes are hidden; only this single fallback renders.
    'content_no_activity_yet' => '',
    // Reviews (S03 hook)
    'heading_reviews' => '',
    'content_reviews_soon' => '',
    // Reviews surface (M053/S03/T04 VenueReviews component)
    'content_no_reviews' => '',
    'label_reviews_count' => '',
    'label_your_rating' => '',
    'placeholder_venue_review' => '',
    'action_submit_venue_review' => '',
    'content_not_eligible' => '',
    'flash_venue_review_submitted' => '',
    'validation_venue_rating_required' => '',
    'validation_venue_body_max' => '',
    // ── Claim a venue (M053/S04/T04) ─────────────────────────────────────────
    'heading_claim_venue' => '',
    'content_claim_subtitle' => '',
    'field_justification' => '',
    'placeholder_justification' => '',
    'heading_optional_proof' => '',
    'field_website' => '',
    'placeholder_claim_website' => '',
    'field_contact_email' => '',
    'hint_contact_email' => '',
    'action_submit_claim' => '',
    'action_claim_venue' => '',
    'action_back_to_venue' => '',
    'content_claim_success' => '',
    'content_claim_reference' => '',
    'error_claim_duplicate' => '',
    'error_claim_rate_limit' => '',
    'error_claim_submission_failed' => '',
    // ── Venue types (internationalized via VenueType::label()) ───────────────
    'type_cafe' => '',
    'type_flgs' => '',
    'type_library' => '',
    'type_community_center' => '',
    'type_convention' => '',
    'type_bar' => '',
    'type_other' => '',
    // ── Venue directory (/{locale}/venues) ───────────────────────────────────
    'nav_venue_directory' => '',
    'heading_directory' => '',
    'content_directory_subtitle' => '',
    'seo_directory_title' => '',
    'seo_directory_description' => '',
    'placeholder_directory_search' => '',
    'field_directory_venue_type' => '',
    'field_directory_sort' => '',
    'field_directory_min_rating' => '',
    'label_directory_any_rating' => '',
    'sort_directory_nearest' => '',
    'sort_directory_active' => '',
    'sort_directory_rating' => '',
    'sort_directory_newest' => '',
    'filter_directory_has_upcoming' => '',
    'filter_directory_managed' => '',
    'action_directory_clear_filters' => '',
    'hint_directory_nearest_needs_location' => '',
    'action_directory_load_more' => '',
    'content_directory_showing_of' => '',
    'content_directory_upcoming_sessions' => '',
    'content_directory_no_upcoming' => '',
    'label_directory_verified' => '',
    'label_directory_managed_badge' => '',
    'action_directory_use_my_location' => '',
    'content_directory_showing_near_you' => '',
    'action_directory_clear_location' => '',
    'empty_directory_title' => '',
    'empty_directory_body' => '',
    'action_directory_cta_propose' => '',
    'action_directory_cta_sign_up_propose' => '',
    'heading_directory_footer_cta' => '',
    'content_directory_footer_cta' => '',
    'heading_directory_portal_card' => '',
    'content_directory_portal_card' => '',
];
