<?php

return [
    // Badge names & descriptions (M069). The registry stores full
    // translation keys; this copy is the source for both locales.
    // Terminology per GLOSSARY: Game/Runde, Host, Campaign/Kampagne.
    'content_badge_debut_name' => 'Debut',
    'content_badge_debut_description' => 'Hosted a first game on Roundup.',
    'content_badge_first_seat_name' => 'First Seat',
    'content_badge_first_seat_description' => 'Attendance verified at a table for the first time. Welcome to the table.',
    'content_badge_founding_table_name' => 'Founding Table',
    'content_badge_founding_table_description' => 'Account created during the platform\'s launch year — there from the start.',
    'content_badge_chartered_name' => 'Chartered',
    'content_badge_chartered_description' => 'Ran a campaign whose charter was fully signed by the entire table.',
    'content_badge_campaign_compleat_name' => 'Campaign Compleat',
    'content_badge_campaign_compleat_description' => 'Completed a campaign with at least six sessions run and journal entries for at least 80% of them.',
    // Money-badge honesty (spec §1.5): the copy certifies financial
    // support, never skill.
    'content_badge_supporting_member_name' => 'Supporting Member',
    'content_badge_supporting_member_description' => 'Supports the platform with an active membership — thank you for keeping the community running.',
    'content_badge_regulars_table_name' => "Regulars' Table",
    'content_badge_regulars_table_description' => 'Attended a verified table month after month — one skip forgiven per six months. Three, six or twelve months keep the seat warm.',
    'content_badge_still_rolling_name' => 'Still Rolling',
    'content_badge_still_rolling_description' => 'The first hosted game lies one, three or five years back — and the dice are still rolling.',
    'content_badge_guild_linked_name' => 'Guild Linked',
    'content_badge_guild_linked_description' => 'A Discord guild has stayed connected to Roundup for over thirty days — its calendar keeps filling with sessions.',
    'content_badge_trusted_organizer_name' => 'Trusted Organizer',
    'content_badge_trusted_organizer_description' => 'Quarter after quarter of well-run events — three or more completed per quarter, an attendee rating of 4.5 or better, and cancellations announced (fewer than one in ten unannounced). One, three or six quarters in a row.',
    'content_badge_mentor_name' => 'Mentor',
    'content_badge_mentor_description' => 'Five, fifteen or forty players rated their first-ever session at this table a perfect five — first impressions done right.',
    // Tier labels interpolated into award-moment copy (M069 S02 T5).
    // One entry per registry tier key so BadgeAwardedNotification can
    // resolve labels in the recipient's locale.
    'content_badge_tier_bronze' => 'Bronze',
    'content_badge_tier_silver' => 'Silver',
    'content_badge_tier_gold' => 'Gold',
    'content_badge_tier_year_1' => 'Year 1',
    'content_badge_tier_year_2' => 'Year 2',
    'content_badge_tier_year_3' => 'Year 3',
    'content_badge_tier_year_5' => 'Year 5',
    // Rarity tier labels (M069 S04, spec §2.4): display grouping derived
    // nightly from the holder share — never a skill claim. content_badge_
    // prefix per the i18n key convention (mirrors content_badge_tier_*).
    'content_badge_rarity_common' => 'Common',
    'content_badge_rarity_uncommon' => 'Uncommon',
    'content_badge_rarity_rare' => 'Rare',
    'content_badge_rarity_elite' => 'Elite',
    'content_badge_rarity_legendary' => 'Legendary',
    // Chip state captions (M069 S04): visible micro-copy inside the shared
    // x-badge-chip pill — greyed lapsed/dormant states and the featured
    // star marker. No tooltip component exists, so these render as text.
    'content_badge_state_re_earn' => 'Re-earn',
    'content_badge_state_coming_soon' => 'Coming soon',
    'content_badge_label_featured' => 'Featured',
    // Profile shelf copy (M069 S04 T3, spec §2.4): section title, owner
    // toggle labels (accessible names for the star button) and the loud
    // max-3 rejection message. 'Badges' is the platform term in EN.
    'content_badge_shelf_title' => 'Badges',
    'content_badge_shelf_feature' => 'Feature this badge',
    'content_badge_shelf_unfeature' => 'Stop featuring this badge',
    'content_badge_shelf_limit_reached' => 'You can feature up to three badges — unfeature one to swap.',
    // Gallery (M069 S04 T5, spec §2.4): category labels resolve through
    // BadgeCategory::label(); gallery page copy + SEO. The public /badges
    // gallery is the discovery + SEO surface for every active definition.
    'category_support_tenure' => 'Support Tenure',
    'category_community_infrastructure' => 'Community Infrastructure',
    'category_gm_craft' => 'GM Craft',
    'category_participation' => 'Participation',
    'category_events_organizing' => 'Events & Organizing',
    'category_milestones' => 'Milestones',
    'title_badge_gallery' => 'Badges',
    'seo_description_badge_gallery' => 'Every Roundup badge in one place — what it celebrates, how rare it is, and who holds it.',
    'content_badge_gallery_intro' => 'Every badge on Roundup, what it celebrates, and how rare it is among the people who can earn it.',
    'filter_all_categories' => 'All',
    'filter_category_label' => 'Category',
    'content_badge_gallery_empty' => 'No badges in this category yet — check back soon.',
    // Boundary-map honesty rule (D195): the raw holder count ALWAYS shows;
    // the eligible share only above BadgeRarity::ELIGIBLE_THRESHOLD.
    'content_badge_holders' => 'Awarded :count time|Awarded :count times',
    'content_badge_share_of_eligible' => 'Held by :percent% of eligible players',
    'content_badge_share_of_eligible_gms' => 'Held by :percent% of eligible Game Masters',
];
