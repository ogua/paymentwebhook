<?php

use App\Http\Controllers\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/paystack', PaystackWebhookController::class);
