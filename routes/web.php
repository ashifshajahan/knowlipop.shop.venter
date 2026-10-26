<?php

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

use App\Http\Middleware\EnsureAdmin;

Route::get('/', function () { return view('welcome'); });
Route::get('/login', function () { return redirect()->route('admin.login'); })->name('login');
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminController::class, 'loginForm'])->name('login');
    Route::post('/login', [AdminController::class, 'login'])->name('login.submit')->middleware('throttle:5,1');
    Route::middleware(['auth', EnsureAdmin::class])->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::post('/sellers', [AdminController::class, 'createSeller'])->name('sellers.store')->middleware('throttle:10,1');
        Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
    });
});
