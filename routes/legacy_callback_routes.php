<?php

use App\Http\Controllers\BusController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\OrangeSmsController;
use App\Http\Controllers\TrajetController;
use App\Http\Controllers\WavePaiementController;
use App\Models\MobileAppLog;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'public/api/mobile'], function () {
    Route::post('payment/om/success', [OrangeMoneyController::class, 'orangeMoneyPaymentSuccessCallBack']);
    Route::post('payment/wave/success', [WavePaiementController::class, 'wavePaymentSuccessCallBack']);
    Route::post('sms/orange/delivery_receipt', [OrangeSmsController::class, 'deliveryReceipt']);
    Route::get('trajets/search/cities', [TrajetController::class, 'searchByCities'])->name('trajets.search.cities');
    Route::get('cities', [TrajetController::class, 'getCities'])->name('trajets.cities');
    Route::get('departs/search', [TrajetController::class, 'searchDepartures']);
    Route::get('departs_for_gp', [MobileAppController::class, 'listeDepartsForGp']);
    Route::get('departs/trajet/{trajet}', [MobileAppController::class, 'listeDepartsTrajet']);
    Route::get('departs/{depart}/schedules', [MobileAppController::class, 'departSchedules']);
    Route::get('departs/{departId}/seats_for_booking', [BusController::class, 'getBusSeats']);

    Route::get('customers/{phoneNumber}/current_booking', [MobileAppController::class, 'currentBooking']);
    Route::get('multiple_bookings/{groupId}', [MobileAppController::class, 'getMultipleBookingsOfSameGroupe']);
    Route::post('bookings/multiple_booking/{groupId}/pay', [MobileAppController::class, 'generatePaymentUrlForMultipleBooking']);
    Route::get('departs/schedules', [MobileAppController::class, 'schedules']);
    Route::post('bookings/multiple_booking/depart/{depart}/calculate_price', [MobileAppController::class, 'calculatePrice']);
    Route::get('bookings/{booking}', [MobileAppController::class, 'showBooking']);
    Route::post('bookings/multiple_booking/calculate_price_for_groupe', [MobileAppController::class, 'calculatePriceForGroupe']);
    Route::delete('bookings/{booking}', [MobileAppController::class, 'cancelBooking']);
    Route::post('bookings/gp_booking/depart/{depart}', [MobileAppController::class, 'handleBookingForGpMultiPassenger']);
    Route::post('bookings/calculate-price', [MobileAppController::class, 'calculatePriceForGpBooking']);
    Route::post('bookings/single_booking/depart/{depart}', [MobileAppController::class, 'saveBooking']);
    Route::post('bookings/multiple_booking/depart/{depart}', [MobileAppController::class, 'saveMultipleBookings']);
    Route::get('payment/wave/get_url/booking/{booking}', [MobileAppController::class, 'getWavePaymentUrlForBooking']);
    Route::get('payment/om/init/booking/{booking}', [MobileAppController::class, 'initOmPayment']);
    Route::get('client_exists/{phoneNumber}', [MobileAppController::class, 'clientExists']);
    Route::get('app_params', [MobileAppController::class, 'params']);
    Route::post('logs', function (Request $request) {

        return response()->json(['message' => 'Log saved']);
    });


});