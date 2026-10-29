<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID)
    |--------------------------------------------------------------------------
    |
    | Keys that sign the notifications sent to phones (courier app). Generate
    | them once with "php artisan app:vapid-keys" and keep them: changing them
    | invalidates every subscription. Without keys, no push is sent.
    |
    */

    'public_key' => env('VAPID_PUBLIC_KEY'),

    'private_key' => env('VAPID_PRIVATE_KEY'),

    // Contact for the push services (Google, Apple, Mozilla) if something goes wrong.
    'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'hello@example.com')),

];
