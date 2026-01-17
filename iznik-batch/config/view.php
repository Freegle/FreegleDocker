<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templating systems load templates from disk. Here you may specify
    | an array of paths that should be checked for your views. Of course
    | the usual Laravel view path has already been registered for you.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This option determines where all the compiled Blade templates will be
    | stored for your application. Typically, this is within the storage
    | directory. However, as usual, you are free to change this value.
    |
    */

    'compiled' => env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views'))),

    /*
    |--------------------------------------------------------------------------
    | Cache Timestamp Checking
    |--------------------------------------------------------------------------
    |
    | When views are precompiled (via artisan view:cache), we can skip
    | timestamp checking entirely. This prevents race conditions where
    | equal timestamps cause unnecessary recompilation attempts.
    |
    | Set to false in phpunit.xml for tests since views are precompiled.
    |
    */

    // Disable timestamp checking in testing environment to use precompiled views unconditionally.
    // Views are precompiled before tests run via `php artisan view:cache`.
    // This prevents race conditions where equal timestamps cause recompilation attempts
    // that can result in empty view renders.
    'check_cache_timestamps' => env('VIEW_CHECK_TIMESTAMPS', env('APP_ENV') !== 'testing'),

];
