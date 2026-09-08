<?php

use Illuminate\Support\Facades\Route;

/*
| The SPA shell. Everything that is not an API endpoint, the Sanctum CSRF
| cookie route or the health check is handed to Vue Router.
*/

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|sanctum|up).*$');
