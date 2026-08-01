<?php

use App\Http\Controllers\DealController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DealController::class, 'index'])->name('dashboard');
