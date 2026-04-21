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

    // Entity type labels (lowercase, for use in sentences)
    'entity_type_game' => 'game',
    'entity_type_campaign' => 'campaign',
    'entity_type_team' => 'team',

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
];
