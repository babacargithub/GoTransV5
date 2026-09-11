<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusController;
use App\Http\Controllers\DepartController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\TicketController;
use App\Livewire\BackOffice\AddBusToDepart;
use App\Livewire\BackOffice\AppParamsPage;
use App\Livewire\BackOffice\BusBookings;
use App\Livewire\BackOffice\CaisseBalancesPage;
use App\Livewire\BackOffice\CreateDepart;
use App\Livewire\BackOffice\DepartList;
use App\Livewire\BackOffice\DepartScheduleNotifications;
use App\Livewire\BackOffice\EditBus;
use App\Livewire\BackOffice\EditDepart;
use App\Livewire\BackOffice\EmployeList;
use App\Livewire\BackOffice\HoraireList;
use App\Livewire\BackOffice\ItineraireList;
use App\Livewire\BackOffice\OrangeMoneyPage;
use App\Livewire\BackOffice\PointDepList;
use App\Livewire\BackOffice\TrajetList;
use App\Livewire\BackOffice\UserAccessManagement;
use App\Livewire\BackOffice\VehiculeList;
use App\Livewire\BackOffice\WavePaymentsPage;
use App\Livewire\Profile\Edit;
use App\Livewire\Website\BookingGroupShow;
use App\Livewire\Website\StudentBooking;
use App\Models\Trajet;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated ticket download page: anyone with the group_id can view/download the tickets.
Route::get('/tickets/group/{groupId}', [TicketController::class, 'showGroupTickets'])->name('tickets.group.show');
Route::domain(config('app.concours_domain'))->group(function () {
    Route::get('/', function () {
        return view('concoursefs.concours');
    })->name('concours');
});
Route::domain(config('app.gp_domain'))->group(function () {
    Route::get('/', function () {
        $trajets = Trajet::select(['id', 'name', 'public_name', 'departure_city', 'arrival_city', 'length'])
            ->with(['departs' => function ($query) {
                $query->where('date', '>=', now())
                    ->where('canceled', false)
                    ->orderBy('date')
                    ->select(['id', 'trajet_id', 'name', 'date', 'closed', 'locked']);
            }])
            ->get()
            ->map(fn ($trajet) => tap($trajet, fn ($t) => $t->length = (float) $t->length));

        return view('gp_booking.gp_booking_index', [
            'trajets' => $trajets,
        ]);
    })->name('gp_booking');
});

/*
 * Public website (customer-facing). Its files live under resources/views/website/
 * and app/Livewire/Website/, mirroring the back-office structure.
 */
Route::domain(config('app.public_website_domain'))->name('website.')->group(function () {
    Route::get('/', function () {
        return view('website.home', [
            'trajets' => Trajet::query()->publiclyVisible()->get(),
        ]);
    })->name('home');

    // Secondary sections linked from the header navigation. Placeholder content for now.
    Route::view('yobante', 'website.yobante')->name('yobante');
    Route::view('aide', 'website.aide')->name('aide');

    // Per-trajet page: lists that trajet's upcoming départs. Resolved by SEO slug.
    // Public URL segment is "caravanes" — the word customers (and SEO) use for a trajet;
    // internally the concept stays "trajet". Reuses MobileAppController@listeDepartsTrajet
    // (same logic as api/mobile/departs/trajet/{trajet}), which branches on the route.
    Route::get('caravanes/{trajet:slug}', [MobileAppController::class, 'listeDepartsTrajet'])
        ->name('caravanes.show');

    // Pickup schedule for one départ card, fetched on demand when a visitor expands
    // "Heures de départ" (keeps the caravane page itself free of per-départ schedule queries).
    Route::get('caravanes/horaires/{depart}', [MobileAppController::class, 'caravaneDepartSchedule'])
        ->name('caravanes.schedule');

    // Student booking funnel — collects the passengers then hands the payload to the
    // untouched mobile booking backend. Transactional page: noindex.
    Route::get('reserver/{depart}', StudentBooking::class)->name('bookings.create');

    // Public booking page, addressed by the group's shared UUID (not the numeric group_id).
    Route::get('reservations/{uuid}', BookingGroupShow::class)->name('bookings.show');
});

Route::get('/', function () {
    return view('homepage');
})->name('home');

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/profile', Edit::class)->name('profile.edit');
});

/*
 * Back office (Livewire/Flux rewrite). Legacy Vue admin keeps hitting the JSON API;
 * these routes reuse the same controllers and render the data into Flux pages instead.
 */
Route::middleware('auth')->prefix('back-office')->name('back-office.')->group(function () {
    Route::get('departs', DepartList::class)->name('departs.index');
    Route::get('departs/create', CreateDepart::class)->name('departs.create');
    Route::get('departs/{depart}/edit', EditDepart::class)->name('departs.edit');
    Route::get('departs/{depart}/add-bus', AddBusToDepart::class)->name('departs.add-bus');
    Route::get('departs/{depart}/schedule-notifications', DepartScheduleNotifications::class)
        ->name('departs.schedule-notifications');
    Route::get('departs/{depart}/bookings-export', [DepartController::class, 'bookingsForExport'])
        ->name('departs.bookings-export');

    Route::get('point-deps', PointDepList::class)->name('point-deps.index');
    Route::get('itineraires', ItineraireList::class)->name('itineraires.index');
    Route::get('horaires', HoraireList::class)->name('horaires.index');
    Route::get('trajets', TrajetList::class)->name('trajets.index');
    Route::get('employes', EmployeList::class)->name('employes.index');
    Route::get('users', UserAccessManagement::class)->name('users.index');
    Route::get('vehicules', VehiculeList::class)->name('vehicules.index');
    Route::get('parametres', AppParamsPage::class)->name('parametres.index');

    Route::get('caisses', CaisseBalancesPage::class)->name('caisses.index');
    Route::get('paiements-om', OrangeMoneyPage::class)->name('paiements-om.index');
    Route::get('paiements-wave', WavePaymentsPage::class)->name('paiements-wave.index');

    Route::get('buses/{bus}/edit', EditBus::class)->name('buses.edit');
    Route::get('buses/{bus}/bookings', BusBookings::class)->name('buses.bookings');
    Route::get('buses/{bus}/bookings-export', [BusController::class, 'bookingsForExport'])
        ->name('buses.bookings-export');

    Route::get('bookings/{booking}/ticket', [TicketController::class, 'showBookingTicket'])
        ->name('bookings.ticket');

    Route::post('bookings/{booking}/save_ticket_payment', [BookingController::class, 'saveTicketPayment'])
        ->name('bookings.save-ticket-payment');
    Route::post('bookings/{booking}/trigger_payment_request/{paymentMethod}', [BookingController::class, 'triggerPaymentRequestForPaymentMethod'])
        ->name('bookings.trigger-payment-request');
});
