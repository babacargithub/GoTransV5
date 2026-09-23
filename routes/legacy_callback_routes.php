<?php

use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\OrangeSmsController;
use App\Http\Controllers\WavePaiementController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'public/api/mobile'], function () {
    Route::post('payment/om/success', [OrangeMoneyController::class, 'orangeMoneyPaymentSuccessCallBack']);
    Route::post('payment/wave/success', [WavePaiementController::class, 'wavePaymentSuccessCallBack']);
    Route::post('sms/orange/delivery_receipt', [OrangeSmsController::class, 'deliveryReceipt']);

});