<?php

use App\Http\Controllers\MapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MapController::class, 'home'])->name('home');
Route::get('/parques', [MapController::class, 'parks'])->name('parks');
Route::get('/mapa', [MapController::class, 'map'])->name('map');
Route::post('/origen', [MapController::class, 'origin'])->name('origin');
Route::post('/busquedas', [MapController::class, 'search'])->name('search');
