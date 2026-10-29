<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    |
    | Off by default: a self-hosted installation never locks anyone out.
    | The hosted service turns it on to manage plans, trials and vouchers.
    |
    */

    'enabled' => (bool) env('BILLING_ENABLED', false),

    // Days a new store can use everything before a plan is needed.
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    // Days after expiry with a warning, before the store is blocked.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // Days before expiry when the panel starts warning.
    'warn_days' => (int) env('BILLING_WARN_DAYS', 5),

    // Where merchants talk to you to buy or renew (shown on the subscription screen).
    'contact_whatsapp' => env('BILLING_CONTACT_WHATSAPP'),

];
