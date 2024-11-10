<?php

use App\Http\Controllers\DepartController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});
