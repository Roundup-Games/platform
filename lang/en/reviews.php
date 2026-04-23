<?php

return [
    // WriteReview page
    'title_write_review' => 'Write a Review',
    'content_for_name' => 'for :name',
    'label_rating' => 'Rating',
    'label_your_review' => 'Your Review',
    'label_gm_strengths' => 'GM Strengths',
    'content_max_3' => 'choose up to 3',
    'content_star_count' => ':count star|:count stars',
    'placeholder_tell_us_about_experience' => 'Tell us about your experience (optional)',
    'action_submit_review' => 'Submit Review',
    'action_write_review' => 'Write Review',
    'action_back_to_game' => 'Back to Game',
    'action_back_to_campaign' => 'Back to Campaign',
    'action_go_back' => 'Go back',
    'content_submitting' => 'Submitting…',

    // Validation
    'validation_rating_required' => 'Please select a rating.',
    'validation_rating_min' => 'Rating must be at least 1.',
    'validation_rating_max' => 'Rating cannot exceed 5.',
    'validation_body_max' => 'Review text cannot exceed 2000 characters.',
    'validation_tags_max' => 'You can select at most 3 strengths.',
    'validation_tags_invalid' => 'Invalid strength selected.',

    // Errors
    'error_not_found' => 'The item you are trying to review could not be found.',
    'error_not_eligible' => 'You are not eligible to review this item.',

    // Flash
    'flash_review_submitted' => 'Your review has been submitted. Thank you!',

    // Review section headings
    'title_reviews' => 'Reviews',
    'title_gm_reviews' => 'GM Reviews',
    'content_no_reviews_yet' => 'No reviews yet.',

    // Review count
    'content_review_count' => ':count review|:count reviews',

    // Report
    'action_report' => 'Report',
    'title_report_review' => 'Report Review',
    'content_report_explanation' => 'Why are you reporting this review? Reports are sent to our moderation team for review.',
    'action_submit_report' => 'Submit Report',
    'action_load_more' => 'Load more reviews',
    'report_reason_inappropriate' => 'Inappropriate content',
    'report_reason_spam' => 'Spam or misleading',
    'report_reason_harassment' => 'Harassment or abuse',
    'report_reason_other' => 'Other',
    'validation_report_reason_required' => 'Please select a reason for reporting.',
    'validation_report_reason_invalid' => 'Please select a valid reason.',
    'error_review_not_found' => 'The review could not be found.',
    'error_already_reported' => 'This review has already been reported.',
    'flash_review_reported' => 'Thank you. This review has been reported to our moderation team.',
];
