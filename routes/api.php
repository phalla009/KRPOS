<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('products/kr/{id}', [ProductController::class, 'showById']);
Route::apiResource('products/kr', ProductController::class)->names('api.products.kr');
Route::apiResource('category/kr', CategoryController::class)->names('api.category.kr');

// Route::get('payments/kr', [PaymentController::class, 'createPayment']);
// Route::post('payment/callback', [PaymentController::class, 'paymentCallback'])->name('api.payment.callback');
