<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/customers/lookup', [CustomerController::class, 'lookup']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/low-stock', [ProductController::class, 'lowStock']);

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/history', [OrderController::class, 'history']);
