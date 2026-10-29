<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp driver
    |--------------------------------------------------------------------------
    |
    | "log" writes messages to the application log (development).
    | "evolution" sends through an Evolution API server.
    |
    */

    'driver' => env('MESSAGING_DRIVER', 'log'),

    'evolution' => [
        'url' => env('EVOLUTION_API_URL', ''),
        'key' => env('EVOLUTION_API_KEY', ''),

        // Instances are named {prefix}{store-slug}. The app only touches instances with this
        // prefix, so it can share an Evolution server with other systems safely.
        'instance_prefix' => env('EVOLUTION_INSTANCE_PREFIX', 'mx-'),
    ],

];
