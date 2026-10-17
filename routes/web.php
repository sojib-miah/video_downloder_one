<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\DownloaderController;

Route::get('/', [DownloaderController::class, 'index']);
Route::post('/media/preview', [DownloaderController::class, 'preview',])->middleware('throttle:10,1');
Route::post('/media/download', [DownloaderController::class, 'start',])->middleware('throttle:5,1');
Route::get('/media/{download}/status', [DownloaderController::class, 'status',])->middleware('throttle:60,1');
Route::get('/media/{download}/file', [DownloaderController::class, 'file',])->middleware('throttle:10,1')->name('media.file');
