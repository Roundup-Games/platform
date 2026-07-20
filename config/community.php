<?php

// Community configuration.
//
// community.auto_follow_on_join
//   When true (production default), a player who joins a game or campaign
//   auto-follows the host so the host's future events surface in the
//   player's activity feed and discovery (M056/S03′). Disable in test
//   environments where the auto-follow would pollute fixture state —
//   set via config(['community.auto_follow_on_join' => false]) or
//   COMMUNITY_AUTO_FOLLOW_ON_JOIN=false in .env.

return [
    'auto_follow_on_join' => env('COMMUNITY_AUTO_FOLLOW_ON_JOIN', true),
];
