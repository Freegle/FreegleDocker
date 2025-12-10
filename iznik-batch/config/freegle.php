<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Freegle Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Freegle-specific settings. These can be overridden
    | via environment variables to support deployment on different domains.
    |
    */

    'sites' => [
        'user' => env('FREEGLE_USER_SITE', 'https://www.ilovefreegle.org'),
        'mod' => env('FREEGLE_MOD_SITE', 'https://modtools.org'),
    ],

    'branding' => [
        'name' => env('FREEGLE_SITE_NAME', 'Freegle'),
        'logo_url' => env('FREEGLE_LOGO_URL', 'https://www.ilovefreegle.org/icon.png'),
        'wallpaper_url' => env('FREEGLE_WALLPAPER_URL', 'https://www.ilovefreegle.org/wallpaper.png'),
    ],

    'images' => [
        'welcome1' => env('FREEGLE_WELCOME_IMAGE1', 'https://www.ilovefreegle.org/images/welcome1.jpg'),
        'welcome2' => env('FREEGLE_WELCOME_IMAGE2', 'https://www.ilovefreegle.org/images/welcome2.jpg'),
        'welcome3' => env('FREEGLE_WELCOME_IMAGE3', 'https://www.ilovefreegle.org/images/welcome3.jpg'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geospatial Settings
    |--------------------------------------------------------------------------
    |
    | SRID 3857 is the Web Mercator projection used for geospatial data.
    |
    */

    'srid' => env('FREEGLE_SRID', 3857),
];
