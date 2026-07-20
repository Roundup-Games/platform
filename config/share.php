<?php

// Share configuration.
//
// share.auto_generate_on_create
//   When true (production default), every new Game and Campaign auto-receives
//   a purpose='share' ShortLink on create so the owner has a copy-ready
//   invite URL immediately (M056/S07). Disable in test environments where
//   the auto-link would pollute fixture state — set via `config(['share.auto_generate_on_create' => false])`
//   or SHARE_AUTO_GENERATE_ON_CREATE=false in .env.

return [
    'auto_generate_on_create' => env('SHARE_AUTO_GENERATE_ON_CREATE', true),
];
