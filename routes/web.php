<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/servidor', [ServerController::class, 'index'])
    ->name('servidor');