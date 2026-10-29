<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Map tiles
    |--------------------------------------------------------------------------
    |
    | The public OpenStreetMap servers are fine to start, but their usage policy
    | does not allow heavy traffic. With volume, point these to a tile provider
    | (MapTiler, Stadia, your own server) that keeps OpenStreetMap data.
    |
    */

    'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),

    'attribution' => env('MAP_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'),

];
