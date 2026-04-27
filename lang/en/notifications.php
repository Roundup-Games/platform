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

    // Notification preferences UI
    'content_notification_preferences' => 'Notification Preferences',
    'action_control_which_notifications_you_receive' => 'Control which notifications you receive and how they are delivered.',
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
    'push_enabled_on_devices' => 'Push notifications enabled on :count device(s).',
    'push_denied_hint' => 'Push notifications are blocked in your browser settings. To re-enable, update the notification permissions in your browser.',

    // Common email strings
    'email_brand_name' => 'Roundup Games',
    'email_default_subject' => 'Notification from Roundup Games',
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
    'body_new_follower' => '**:follower** started following you on Roundup Games. Check out their profile and say hello!',
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
];
