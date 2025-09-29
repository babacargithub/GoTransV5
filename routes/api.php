<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DepartController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\MessengerController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\PointDepController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TrajetController;
use App\Http\Controllers\WavePaiementController;
use App\Models\MobileAppLog;
use App\Models\Vehicule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// =========================== PUBLIC ROUTES ===========================

Route::get('/contacts/latest', [CustomerController::class, 'getLatestContacts']);

Route::prefix('messenger')->group(function () {
    Route::get('upcoming-departs', [DepartController::class, 'upcomingDepartsForMessenger']);
// Register phone calls
    Route::post('register_phone_call_logs', [EmployeController::class, 'registerPhoneCallLogs']);
    Route::post('register_single_call', [EmployeController::class, 'logNewCall']);
    // Get batch of SMS messages
    Route::get('/batch', [MessengerController::class, 'getSmsBatch'])
        ->name('messenger.batch');

    // Update SMS status
    Route::post('/status', [MessengerController::class, 'updateSmsStatus'])
        ->name('messenger.status');

    // Device registration
    Route::post('/device/register', [MessengerController::class, 'registerDevice'])
        ->name('messenger.device.register');

    // Device heartbeat
    Route::post('/device/heartbeat', [MessengerController::class, 'sendHeartbeat'])
        ->name('messenger.device.heartbeat');

    Route::post('create-messages', [MessengerController::class, 'createMessages'])->name('messenger.create-messages');
    Route::post('next-message-to-send', [MessengerController::class, 'nextMessageToSend']);
    Route::post('mark-message-as-processing', [MessengerController::class, 'markMessageAsProcessing']);
    Route::post('report-message-sending-result', [MessengerController::class, 'reportMessageSendingResult']);
    // departs 3213
    Route::get('departs-for-bulk-sms', [MessengerController::class, 'getDepartsForBulkSms']);
    Route::get('departs-bookings-for-bulk-sms/{depart}', [MessengerController::class, "getDepartCustomersForBulkSms"]);
    Route::get('download-file/{filename}', [MessengerController::class, 'downloadFile'])->name('messenger.download-file');
    Route::get('batch-excel-files', [MessengerController::class, 'getBatchExcelFiles']);
    Route::get("call-logs", [MessengerController::class,'callLogs']);


});

Route::post('/login', function (Request $request) {
    $validated = $request->validate([
        'username' => 'required',
        'password' => 'required'
    ]);
    if (auth()->attempt($validated)) {
        $user = auth()->user();
        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'token' => $token,
            'user' => [
                "username" => $user->username,
                "name" => $user->name,
                "email" => $user->email,
                "roles"=> \App\Models\User::resolveRoles(unserialize($user->roles)),
                "canSellTicket" => (boolean)$user->canSellTicket,
                "balance" => $user->balance??0,
                "shouldChangePassword" => (boolean) $user->shouldChangePassword ?? false,
                "id" => $user->id
            ]
        ]);
    }
    return response()->json([
        'message' => 'Invalid credentials'
    ], 401);
});

//// =========================== PROTECTED ROUTES ===========================
Route::group(["middleware" => "auth:sanctum"],function (){
Route::get("buses/{bus}/bookings", [BusController::class, 'bookings']);
Route::get("buses/{bus}/bookings_for_export", [BusController::class, 'bookingsForExport']);
Route::get("departs/{depart}/bookings_grouping", [DepartController::class, 'bookingGroupingsCount']);
Route::get("departs/{depart}/bus_stop_schedules", [DepartController::class, 'busStopSchedules']);
Route::put("departs/{depart}/bus_stop_schedules/update", [DepartController::class, 'updateBusStopSchedules']);
Route::get("departs/{depart}/bookings_for_notification", [DepartController::class, 'bookingsForNotification']);
Route::get("departs/{depart}/ticket_sales", [DepartController::class, 'ticketSales']);
Route::put("departs/{depart}/cancel", [DepartController::class, 'cancelDepart']);
Route::get("departs/bookings_counts", [DepartController::class, 'bookingsCount']);
Route::post("departs/{depart}/add_bus", [DepartController::class, 'addBusToDepart']);
Route::post("departs/{depart}/new_booking", [BookingController::class, 'store']);
Route::get("departs/{depart}/bookings", [DepartController::class, 'bookings']);
Route::get("departs/{depart}/bookings_for_export", [DepartController::class, 'bookingsForExport']);
Route::get("departs/data_for_creation", [DepartController::class, 'getDataForDepartCreation']);
Route::get("departs/current_events/departs", [DepartController::class, 'getAutresDeparts']);
Route::get("departs/{depart}/waiting_customers", [DepartController::class, 'waitingCustomers']);


Route::resource('departs', DepartController::class)->only(['index', 'store', 'update', 'destroy']);;


Route::get("buses/{bus}/ticket_sales", [BusController::class, 'busTicketSales']);
Route::put("buses/{bus}/toggle_close", [BusController::class, 'toggleClose']);
Route::put("buses/{bus}/toggle_seat_visibility", [BusController::class, 'toggleBusSeatVisibility']);
Route::get("buses/{bus}/seats", [BusController::class, 'seats']);
Route::post("buses/{bus}/seats/bulk-action", [BusController::class, 'performBulkAction']);
Route::get("buses/vehicules", [BusController::class, 'vehicules']);
Route::post("buses/{bus}/add_missing_point_dep_heures", [DepartController::class, 'addPointDepsSchedulesForBus']);
Route::post("buses/{bus}/import_yobuma_passengers", [BusController::class, 'importPassengersFromYobuma']);
Route::put("buses/{sourceBus}/transfer_bookings", [BusController::class, 'transferBookings']);
Route::resource('buses', BusController::class);

// Finances
Route::get("finance/caisses", [TicketController::class, 'index']);

// Bookings
Route::get("bookings/{booking}/trigger_payment_request/{paymentMethod}", [BookingController::class, 'triggerPaymentRequestForPaymentMethod']);
Route::put("bookings/{booking}/save_ticket_payment", [BookingController::class, 'saveTicketPayment']);
Route::put("bookings/{booking}/transfer/target_bus/{targetBus}", [BookingController::class, 'transferBooking']);
Route::post("bookings/{booking}/send_schedule_notification", [BookingController::class, 'sendScheduleNotification']);
Route::post("bookings/{booking}/refund", [BookingController::class, 'refundTicket']);

Route::resource('bookings', BookingController::class)->only(['index', 'store', 'update', 'destroy']);
Route::resource("point_departs", PointDepController::class);
Route::resource("customers", CustomerController::class);
Route::get("events/{event}/departs", [EventController::class, 'departs']);
Route::get("events/{event}/ticket_sales", [EventController::class, 'ticketSales']);
Route::resource("events", EventController::class);
Route::get("events/stats/periode/{dateStart}/{dateEnd}", [EventController::class, 'stats']);
Route::group(["prefix" => "discounts/"], function () {
    Route::get("", [TicketController::class, 'discounts']);

});
Route::resource("employees", EmployeController::class);
Route::group(['prefix' => 'finance'], function () {
    Route::group(["prefix" => "wave"], function () {
        Route::get("refund/{transaction_id}", [WavePaiementController::class, 'refundTransaction']);
    });
    Route::group(["prefix" => "om"], function () {
        Route::get("transactions", [OrangeMoneyController::class, 'transactions']);
        Route::get("balance", [OrangeMoneyController::class, 'balance']);
        Route::post("withdraw", [OrangeMoneyController::class, 'withdraw']);
    });
});
Route::get("vehicules",function (){
    return Vehicule::all();

});
Route::resource("trajets", TrajetController::class);
Route::resource("itineraires", ItineraryController::class);
Route::post("itineraires/{itinerary}/delete_multiple", [ItineraryController::class, 'deleteMultiplePointDeparts']);
    Route::post("itineraires/{itinerary}/add_multiple", [ItineraryController::class, 'addMultiplePointDeparts']);

// admin routes
Route::group(["prefix" => "admin"],function (){
    Route::put("app/update_params", [MobileAppController::class, 'updateParams']);

});
});

//   ================ mobile app routes ==============
Route::group(['prefix' => 'mobile'], function () {
    Route::get("trajets/search/cities", [TrajetController::class, 'searchByCities'])->name("trajets.search.cities");
    Route::get("cities", [TrajetController::class, 'getCities'])->name("trajets.cities");
    Route::get("departs/search", [TrajetController::class, 'searchDepartures']);
    Route::get("departs_for_gp", [MobileAppController::class, 'listeDepartsForGp']);
    Route::get("departs/trajet/{trajet}", [MobileAppController::class, 'listeDepartsTrajet']);
    Route::get("departs/{depart}/schedules", [MobileAppController::class, 'departSchedules']);
    Route::get("departs/{departId}/seats_for_booking", [BusController::class, 'getBusSeats']);

    Route::get("customers/{phoneNumber}/current_booking", [MobileAppController::class, 'currentBooking']);
    Route::get("multiple_bookings/{groupId}", [MobileAppController::class, 'getMultipleBookingsOfSameGroupe']);
    Route::post("bookings/multiple_booking/{groupId}/pay", [MobileAppController::class, 'generatePaymentUrlForMultipleBooking']);
    Route::get("departs/schedules", [MobileAppController::class, 'schedules']);
    Route::post("bookings/multiple_booking/depart/{depart}/calculate_price", [MobileAppController::class, 'calculatePrice']);
    Route::get("bookings/{booking}", [MobileAppController::class, 'showBooking']);
    Route::post("bookings/multiple_booking/calculate_price_for_groupe", [MobileAppController::class, 'calculatePriceForGroupe']);
    Route::delete("bookings/{booking}", [MobileAppController::class, 'cancelBooking']);
    Route::post("bookings/gp_booking/depart/{depart}", [MobileAppController::class, 'handleBookingForGpMultiPassenger']);
    Route::post("bookings/single_booking/depart/{depart}", [MobileAppController::class, 'saveBooking']);
    Route::post("bookings/multiple_booking/depart/{depart}", [MobileAppController::class, 'saveMultipleBookings']);
    Route::get("payment/wave/get_url/booking/{booking}", [MobileAppController::class, 'getWavePaymentUrlForBooking']);
    Route::get("payment/om/init/booking/{booking}", [MobileAppController::class, 'initOmPayment']);
    Route::get("client_exists/{phoneNumber}", [MobileAppController::class, 'clientExists']);
    Route::get("app_params", [MobileAppController::class, 'params']);
    Route::post("logs",function (Request $request){
        $logs = $request->input("logs");
        foreach ($logs as $log){
            $mobileAppLog = new MobileAppLog($log);
            $mobileAppLog->save();
        }
        return response()->json(["message" => "Log saved"]);
    });
    Route::post("payment/om/success",[OrangeMoneyController::class,"orangeMoneyPaymentSuccessCallBack"]);
    Route::post("payment/wave/success",[WavePaiementController::class,"wavePaymentSuccessCallBack"]);

});