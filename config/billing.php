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

];
