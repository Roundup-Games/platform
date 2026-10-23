<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paddle Price IDs
    |--------------------------------------------------------------------------
    |
    | These are the Paddle price IDs for each membership plan.
    | Set them in your .env file. In sandbox mode, use sandbox price IDs.
    |
    */

    'annual_price_id' => env('PADDLE_ANNUAL_PRICE_ID'),
    'monthly_price_id' => env('PADDLE_MONTHLY_PRICE_ID'),

    /*
    |----------------------------------------------------------------------
    | GM Membership Price IDs (D184)
    |----------------------------------------------------------------------
    |
    | The paid Game Master tier — €9.50/month, €95/year. Until these are
    | set, the GM plans stay hidden from /pricing and /membership (the
    | listing requires a price id) and checkout refuses them cleanly.
    |
    */

    'gm_monthly_price_id' => env('PADDLE_GM_MONTHLY_PRICE_ID'),
    'gm_annual_price_id' => env('PADDLE_GM_ANNUAL_PRICE_ID'),

];
