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

    // Empty states
    'content_no_upcoming_sessions' => 'No upcoming sessions at this venue yet.',
    'content_no_past_sessions' => 'No past sessions yet.',
    'content_no_active_campaigns' => 'No active campaigns at this venue yet.',
    'content_no_completed_campaigns' => 'No past campaigns yet.',

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
];
