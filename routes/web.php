<?php

use Illuminate\Support\Facades\Route;

// The SPA shell. Everything but api, sanctum and the health check goes to Vue.

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|sanctum|up).*$');
