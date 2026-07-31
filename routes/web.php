<?php

use App\Http\Controllers\DepartController;
use App\Http\Controllers\TicketController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::domain(config('app.concours_domain'))->group(function () {
    Route::get('/', function () {
        return view('concoursefs.concours');
    })->name('concours');
});
Route::domain(config('app.gp_domain'))->group(function () {
    Route::get('/', function () {
        $trajets = \App\Models\Trajet::select(['id', 'name', 'public_name', 'departure_city', 'arrival_city', 'length'])
            ->with(['departs' => function ($query) {
                $query->where('date', '>=', now())
                    ->where('canceled', false)
                    ->orderBy('date')
                    ->select(['id', 'trajet_id', 'name', 'date', 'closed', 'locked']);
            }])
            ->get()
            ->map(fn($trajet) => tap($trajet, fn($t) => $t->length = (float) $t->length));

        return view('gp_booking.gp_booking_index', [
            'trajets' => $trajets,
        ]);
    })->name('gp_booking');
});

/*Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
        "messages"=> \App\Models\Depart::notPassed()->get()->map(function ($point){
            return $point->name;
        })
    ]);
});*/
Route::get('/', function () {
    $trajet = \App\Models\Trajet::first();
    $messages = app(\App\Http\Controllers\MobileAppController::class)->listeDepartsTrajet($trajet)->getData();


    return view('home', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => \Illuminate\Foundation\Application::VERSION,
        'phpVersion' => PHP_VERSION,
        "departs" => $messages->departs,
    ]);
});

// Public, unauthenticated ticket download page: anyone with the group_id can view/download the tickets.
Route::get('/tickets/group/{groupId}', [TicketController::class, 'showGroupTickets'])->name('tickets.group.show');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});
