<?php

use App\Http\Controllers\IncomingMailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Incoming mail endpoint for Postfix pipe delivery
// This is called by the postfix container via HTTP
Route::post('/api/mail/incoming', [IncomingMailController::class, 'receive'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
