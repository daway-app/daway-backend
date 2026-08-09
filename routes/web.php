<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\web\Auth\LoginController;
use App\Http\Controllers\web\Auth\ForgotPasswordController;
use App\Http\Controllers\web\Admin\AdminDashboardController;
use App\Http\Controllers\web\Pharmacy\PharmacyDashboardController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/forgot-password', [ForgotPasswordController::class, 'showForgotForm'])->name('password.request');
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendRequest'])->name('password.email');

// مسار الأدمن
Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
// مسار الصيدلية
Route::get('/pharmacy/dashboard', [PharmacyDashboardController::class, 'index'])->name('pharmacy.dashboard');
