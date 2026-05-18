<?php

return [
    // Category labels
    'category_new_follower' => 'New Follower',
    'category_game_invitation' => 'Game Invitation',
    'category_campaign_invitation' => 'Campaign Invitation',
    'category_team_invitation' => 'Team Invitation',
    'category_session_added_to_campaign' => 'Session Added to Campaign',
    'category_new_application' => 'New Application',
    'category_application_approved' => 'Application Approved',
    'category_application_rejected' => 'Application Rejected',
    'category_participant_joined' => 'Participant Joined',
    'category_participant_removed' => 'Participant Removed',
    'category_team_member_removed' => 'Team Member Removed',
    'category_game_cancelled' => 'Game Cancelled',
    'category_game_completed' => 'Game Completed',
    'category_campaign_cancelled' => 'Campaign Cancelled',
    'category_campaign_completed' => 'Campaign Completed',

    // Moderation
    'category_review_reported' => 'Review Reported',
    'group_moderation' => 'Moderation',

    // UI — Bell dropdown
    'bell_label' => 'Notifications (:count unread)',
    'nav_label' => 'Notifications',
    'dropdown_heading' => 'Notifications',
    'action_mark_all_read' => 'Mark all read',
    'action_mark_read' => 'Mark as read',
    'action_view_all' => 'View all notifications',
    'label_notifications' => 'notifications',
    'empty_state' => 'No notifications yet',
    'page_title' => 'Notifications',

    // Group labels
    'group_social' => 'Social',
    'group_invitations' => 'Invitations',
    'group_applications' => 'Applications',
    'group_participation' => 'Participation',
    'group_status' => 'Status',
    'group_content' => 'Content',
    'group_scheduling' => 'Scheduling',

    // Notification preferences UI
    'content_notification_preferences' => 'Notification Preferences',
    'flash_notification_preferences_saved' => 'Notification preferences saved.',
    'channel_in_app' => 'In-App',
    'channel_email' => 'Email',

    // Trinary state labels
    'state_off' => 'Off',
    'state_in_app' => 'In-App',
    'state_all' => 'All',
    'hint_preference_states' => 'Choose how you want to be notified for each category. "In-App" shows notifications in the bell icon. "All" also sends email.',
    'hint_preference_channels' => 'Toggle each channel on or off per notification category. Push notifications require enabling in the device section below.',
    'channel_push' => 'Push',

    // Push subscription management
    'push_devices_heading' => 'Push Devices',
    'push_not_supported' => 'Push notifications are not supported in this browser.',
    'push_enable_description' => 'Enable push notifications to receive alerts on this device.',
    'push_enable_button' => 'Enable Push',
    'push_disable_button' => 'Disable',

    // Common email strings
    'email_brand_name' => ':brand',
    'email_default_subject' => 'Notification from :brand',
    'email_unsubscribe' => 'Unsubscribe',
    'email_manage_settings' => 'Notification Settings',
    'email_footer_reason' => 'You received this because you have notifications enabled for this type of activity.',
    'email_greeting' => 'Hey :name,',
    'email_greeting_plain' => 'Hey there,',
    'email_view_details' => 'View Details',
    'email_view_entity' => 'View :entity',
    'email_manage_participants' => 'Manage Participants',

    // Unsubscribe
    'unsubscribe_success' => 'You have been unsubscribed from :category email notifications.',
    'unsubscribe_invalid_link' => 'This unsubscribe link is invalid or has expired.',
    'unsubscribe_unknown_category' => 'The notification category could not be found.',

    // Entity type labels (lowercase, for use in sentences)
    'entity_type_game' => 'game',
    'entity_type_campaign' => 'campaign',
    'entity_type_team' => 'team',

    // Display strings for notification list (verb + sentence templates)
    'verb_new_follower' => 'followed you',
    'verb_game_invitation' => 'invited you to a game',
    'verb_campaign_invitation' => 'invited you to a campaign',
    'verb_team_invitation' => 'invited you to a team',
    'verb_session_added_to_campaign' => 'added a session to your campaign',
    'verb_new_application' => 'applied to join',
    'verb_application_approved' => 'approved your application',
    'verb_application_rejected' => 'rejected your application',
    'verb_participant_joined' => 'joined',
    'verb_participant_removed' => 'removed you from',
    'verb_team_member_removed' => 'removed you from a team',
    'verb_game_cancelled' => 'Game cancelled',
    'verb_game_completed' => 'Game completed',
    'verb_campaign_cancelled' => 'Campaign cancelled',
    'verb_campaign_completed' => 'Campaign completed',

    // Display strings — sentence templates (1/2/3+ actors)
    'display_one_actor' => ':actor :verb',
    'display_two_actors' => ':actor1 and :actor2 :verb',
    'display_many_actors' => ':actor1, :actor2, and :count other :verb|:actor1, :actor2, and :count others :verb',
    'display_no_actor_with_entity' => ':verb: :entity',
    'display_no_actor' => ':verb',

    // Email subjects — Social
    'subject_new_follower' => ':follower started following you',
    'body_new_follower' => '**:follower** started following you on :brand. Check out their profile and say hello!',
    'action_new_follower' => 'View Profile',

    // Email subjects — Invitations
    'subject_game_invitation' => ':inviter invited you to a game',
    'body_game_invitation' => '**:inviter** invited you to join their game **:game**. Check out the details and RSVP.',
    'action_game_invitation' => 'View Game',

    'subject_campaign_invitation' => ':inviter invited you to a campaign',
    'body_campaign_invitation' => '**:inviter** invited you to join the campaign **:campaign**. A whole adventure awaits.',
    'action_campaign_invitation' => 'View Campaign',

    'subject_team_invitation' => ':inviter invited you to join :team',
    'body_team_invitation' => '**:inviter** invited you to join the team **:team**. Come play together.',
    'action_team_invitation' => 'View Team',

    'subject_session_added_to_campaign' => 'New session added to :campaign',
    'body_session_added_to_campaign' => 'A new session was added to the campaign **:campaign**. Check the details and mark your calendar.',
    'action_session_added_to_campaign' => 'View Session',

    // Email subjects — Applications
    'subject_new_application' => ':applicant applied to join your :entity',
    'body_new_application' => '**:applicant** applied to join your :entity_type **:entity**. Review their profile and respond.',
    'action_new_application' => 'Review Application',

    'subject_application_approved' => 'Your application to :entity was approved',
    'body_application_approved' => 'Great news! Your application to join **:entity** has been approved. You\'re in!',
    'action_application_approved' => 'View :entity_type',

    'subject_application_rejected' => 'Your application to :entity was not accepted',
    'body_application_rejected' => 'Your application to join **:entity** was not accepted this time. Don\'t worry — there are plenty more games out there.',
    'action_application_rejected' => 'Browse Games',

    // Email subjects — Participation
    'subject_participant_joined' => ':participant joined your :entity',
    'body_participant_joined' => '**:participant** joined your :entity_type **:entity**. The more the merrier!',
    'action_participant_joined' => 'View :entity_type',

    'subject_participant_removed' => 'You were removed from :entity',
    'body_participant_removed' => 'You were removed from **:entity**. If you have questions, reach out to the organizer.',
    'action_participant_removed' => 'Browse Games',

    'subject_team_member_removed' => 'You were removed from :team',
    'body_team_member_removed' => 'You were removed from the team **:team**. If you have questions, contact the team organizer.',
    'action_team_member_removed' => 'Browse Teams',

    // Email subjects — Status
    'subject_game_cancelled' => ':game has been cancelled',
    'body_game_cancelled' => 'The game **:game** scheduled for :date has been cancelled. Check out other available games.',
    'action_game_cancelled' => 'Browse Games',

    'subject_game_completed' => ':game has been completed',
    'body_game_completed' => 'The game **:game** is now complete. Thanks for playing!',
    'action_game_completed' => 'View Game',

    'subject_campaign_cancelled' => ':campaign has been cancelled',
    'body_campaign_cancelled' => 'The campaign **:campaign** has been cancelled. Browse other campaigns to find your next adventure.',
    'action_campaign_cancelled' => 'Browse Campaigns',

    'subject_campaign_completed' => ':campaign has been completed',
    'body_campaign_completed' => 'The campaign **:campaign** is now complete. What a journey! Browse for your next adventure.',
    'action_campaign_completed' => 'View Campaign',

    // Email subjects — Moderation
    'subject_review_reported' => 'A review has been reported',
    'body_review_reported' => '**:reporter** reported a review for: **:reason**.',
    'body_review_rating' => 'Review rating: :rating/5',
    'body_review_content' => 'Review content: :body',

    // Game updated
    'category_game_updated' => 'Game Updated',
    'subject_game_updated' => ':game has been updated',
    'body_game_updated' => 'The game **:game** has been updated. Changes: :fields.',
    'action_view_game' => 'View Game',

    // Game system request
    'category_game_system_request' => 'Game System Request',
    'category_below_min_players' => 'Below Min Players',
    'category_confirmation_expired' => 'Confirmation Expired',
    'category_session_reminder' => 'Session Reminder',
    'subject_game_system_request_approved' => 'Game System Added: :name',
    'body_game_system_request_approved' => 'Your game system request for **:name** has been approved! It\'s now available on the platform.',
    'action_create_game' => 'Create a Game',
    'subject_game_system_request_rejected' => 'Game System Request Update',
    'body_game_system_request_rejected' => 'Your game system request for **:name** could not be added at this time.',
    'body_rejection_reason' => 'Reason: :reason',
    'subject_game_system_request_duplicate' => 'Game System Already Exists',
    'body_game_system_request_duplicate' => 'The game system **:name** you requested already exists as **:existing**.',
    'action_view_game_system' => 'View Game System',

    // Campaign updated
    'category_campaign_updated' => 'Campaign Updated',
    'subject_campaign_updated' => ':campaign has been updated',
    'body_campaign_updated' => 'The campaign **:campaign** has been updated. Changes: :fields.',
    'action_view_campaign' => 'View Campaign',

    // Push notification titles & bodies
    'push_title_game_invitation' => 'Game Invitation',
    'push_body_game_invitation' => ':inviter invited you to :game',
    'push_title_campaign_invitation' => 'Campaign Invitation',
    'push_body_campaign_invitation' => ':inviter invited you to :campaign',
    'push_title_new_follower' => 'New Follower',
    'push_body_new_follower' => ':follower started following you',
    'push_title_game_cancelled' => 'Game Cancelled',
    'push_body_game_cancelled' => ':game has been cancelled',
    'push_title_campaign_cancelled' => 'Campaign Cancelled',
    'push_body_campaign_cancelled' => ':campaign has been cancelled',
    'push_title_session_reminder' => 'Game Reminder',
    'push_body_session_reminder' => ':game starts at :time today',

    // Waitlist
    'subject_waitlist_promoted' => 'A spot opened in :game!',
    'body_waitlist_promoted' => 'A spot opened up in **:game**! You have until :deadline to confirm your participation.',
    'action_waitlist_promoted' => 'Confirm Your Spot',
    'subject_confirmation_expired' => 'Waitlist confirmation expired for :game',
    'body_confirmation_expired' => 'Your confirmation window for **:game** has expired. You have been moved to the back of the waitlist.',
    'subject_below_min_players' => ':game needs more players',
    'body_below_min_players' => '**:game** currently has :current players but requires a minimum of :min. Consider inviting more players.',

    // Player benched
    'subject_player_benched' => 'You\'ve been placed on the bench for :entity',
    'body_player_benched' => 'The roster for **:entity** is currently full, so you\'ve been placed on the bench. If a spot opens up, the host can promote you.',
    'action_player_benched' => 'View :entity_type',
    'push_title_player_benched' => 'You\'re on the Bench',
    'push_body_player_benched' => 'You\'ve been placed on the bench for :entity',

    // Attendance
    'category_attendance_reported' => 'Attendance Reported',
    'category_dispute_resolved' => 'Dispute Resolved',
    'subject_attendance_reported' => 'Attendance report for :game',
    'body_attendance_reported' => 'Your attendance for **:game** on :date was recorded as **:status**. If you disagree, you can dispute this report.',
    'action_dispute_attendance' => 'Dispute Report',
    'push_title_attendance_reported' => 'Attendance Report',
    'push_body_attendance_reported' => 'Your attendance for :game was reported as :status',

    // Dispute resolution
    'subject_dispute_resolved_favor' => 'Dispute resolved — attendance cleared for :game',
    'body_dispute_resolved_favor' => 'Your dispute for **:game** on :date has been resolved in your favor. The no-show report has been cleared and your attendance updated.',
    'subject_dispute_upheld' => 'Dispute reviewed — report upheld for :game',
    'body_dispute_upheld' => 'Your dispute for **:game** on :date was reviewed. The attendance report has been upheld, though its impact on your reliability has been reduced.',
    'push_title_dispute_resolved_favor' => 'Dispute Resolved',
    'push_body_dispute_resolved_favor' => 'Your dispute for :game was resolved in your favor',
    'push_title_dispute_upheld' => 'Dispute Reviewed',
    'push_body_dispute_upheld' => 'The attendance report for :game was upheld',

    // Recap posted
    'subject_recap_posted' => ':host wrote a recap for :game',
    'body_recap_posted' => '**:host** wrote a post-session recap for **:game**. Check it out!',
    'action_view_recap' => 'View Recap',
    'push_title_recap_posted' => 'Session Recap Posted',
    'push_body_recap_posted' => ':host wrote a recap for :game',

    // Debriefing available
    'subject_debriefing_available' => 'Share your thoughts on :game',
    'body_debriefing_available' => '**:game** has been completed with debriefing tools. Take a moment to reflect on the session and share your feedback.',
    'action_submit_debriefing' => 'Submit Debriefing',
    'push_title_debriefing_available' => 'Session Debriefing Available',
    'push_body_debriefing_available' => 'Share your thoughts on :game',

    // 24-hour session reminder
    'push_title_session_reminder_24h' => 'Session Tomorrow',
    'push_body_session_reminder_24h' => ':game starts tomorrow at :time',

    // Waitlist push
    'push_title_waitlist_promoted' => 'Spot Available!',
    'push_body_waitlist_promoted' => 'A spot opened up in :game. Confirm by :deadline.',
    'push_title_confirmation_expired' => 'Confirmation Expired',
    'push_body_confirmation_expired' => 'Your confirmation window for :game has expired.',

    'subject_waitlist_expired_rejected' => 'Removed from waitlist for :game',
    'body_waitlist_expired_rejected' => 'You have been removed from the waitlist for **:game** because you did not confirm your spot after :attempts promotions. You can join the waitlist for other games at any time.',
    'push_title_waitlist_expired_rejected' => 'Removed from Waitlist',
    'push_body_waitlist_expired_rejected' => 'You were removed from the waitlist for :game due to missed confirmations.',
    'action_browse_games' => 'Browse Games',

    'push_title_below_min_players' => 'Low Roster Warning',
    'push_body_below_min_players' => ':game has only :current/:min players.',

    // Content moderation notifications
    'subject_content_warning' => 'Community Guidelines Warning',
    'body_content_warning' => 'Your :entityType ":entityName" has been flagged for review by our moderation team.',
    'body_content_warning_reason' => 'Reason: :reason',
    'body_content_warning_guidelines' => 'Please review our community guidelines to ensure your content complies with our policies.',

    'subject_content_removed' => 'Content Removed',
    'body_content_removed' => 'Your :entityType ":entityName" has been removed by our moderation team.',
    'body_content_removed_reason' => 'Reason: :reason',
    'body_content_removed_guidelines' => 'Repeated violations may result in account suspension.',

    'subject_account_suspended' => 'Account Suspended',
    'body_account_suspended' => 'Your account has been suspended due to a violation of our community guidelines.',
    'body_account_suspended_reason' => 'Reason: :reason',
    'body_account_suspended_contact' => 'If you believe this is an error, please contact our support team.',
];
