<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/status', [DashboardController::class, 'status'])->name('status');
Route::post('/ping', [DashboardController::class, 'ping'])->name('ping')->middleware('throttle:10,1');
