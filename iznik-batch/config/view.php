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
    | For parallel test execution (ParaTest), each worker can have its own
    | compiled view cache to prevent race conditions where multiple workers
    | try to compile the same view simultaneously.
    |
    */

    'compiled' => env(
        'PARATEST_VIEW_CACHE',
        env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views')))
    ),

];
