<?php

use App\Http\Controllers\AmbassadorProgramDocumentController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusController;
use App\Http\Controllers\DepartController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\SeoFilesController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\CachePublicHtmlResponse;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureUserIsAmbassador;
use App\Http\Middleware\RejectAmbassadorAccounts;
use App\Http\Middleware\VerifyCsrfToken;
use App\Livewire\Ambassador\EarningsPage as AmbassadorEarnings;
use App\Livewire\Ambassador\Home as AmbassadorHome;
use App\Livewire\Ambassador\PayoutsPage as AmbassadorPayouts;
use App\Livewire\Ambassador\SponsoredAmbassadors;
use App\Livewire\BackOffice\AccountListPage;
use App\Livewire\BackOffice\AddBusToDepart;
use App\Livewire\BackOffice\AmbassadorList;
use App\Livewire\BackOffice\AmbassadorPayoutList;
use App\Livewire\BackOffice\AmbassadorRewardRuleList;
use App\Livewire\BackOffice\AppParamsPage;
use App\Livewire\BackOffice\BusBookings;
use App\Livewire\BackOffice\CaisseBalancesPage;
use App\Livewire\BackOffice\CreateDepart;
use App\Livewire\BackOffice\DepartCostReportList;
use App\Livewire\BackOffice\DepartCostReportPage;
use App\Livewire\BackOffice\DepartList;
use App\Livewire\BackOffice\DepartScheduleNotifications;
use App\Livewire\BackOffice\EditBus;
use App\Livewire\BackOffice\EditDepart;
use App\Livewire\BackOffice\EmployeList;
use App\Livewire\BackOffice\ExpenseManagement;
use App\Livewire\BackOffice\ExpenseTypeList;
use App\Livewire\BackOffice\ExpenseTypeOperations;
use App\Livewire\BackOffice\HoraireList;
use App\Livewire\BackOffice\ItineraireList;
use App\Livewire\BackOffice\OrangeMoneyPage;
use App\Livewire\BackOffice\PointDepList;
use App\Livewire\BackOffice\ProfitReportPage;
use App\Livewire\BackOffice\TrajetList;
use App\Livewire\BackOffice\UserAccessManagement;
use App\Livewire\BackOffice\VehiculeList;
use App\Livewire\BackOffice\WavePaymentsPage;
use App\Livewire\Profile\Edit;
use App\Livewire\Website\AmbassadorInvitation;
use App\Livewire\Website\BookingGroupShow;
use App\Livewire\Website\StudentBooking;
use App\Models\Trajet;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
 * Ambassador space: ambassadors log in (shared /login) on their own domain and only see their pages.
 */
Route::domain(config('app.ambassador_domain'))
    ->middleware(['auth', EnsureUserIsAmbassador::class])
    ->name('ambassador.')
    ->group(function () {
        Route::get('/', AmbassadorHome::class)->name('home');
        Route::get('gains', AmbassadorEarnings::class)->name('earnings');
        Route::get('filleuls', SponsoredAmbassadors::class)->name('sponsored-ambassadors');
        Route::get('paiements', AmbassadorPayouts::class)->name('payouts');
    });

/*
 * One-time link to the ambassador program document, shared with candidates (who have no account).
 * No session/cookies: nothing to remember, and the response must never be cached or indexed.
 */
Route::domain(config('app.ambassador_domain'))
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
    ])
    ->get('programme/{link}', [AmbassadorProgramDocumentController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('ambassador-program-document');

/*
 * Public website (customer-facing). Its files live under resources/views/website/
 * and app/Livewire/Website/, mirroring the back-office structure.
 */
Route::domain(config('app.public_website_domain'))->name('website.')->group(function () {

    /*
     * Read-only, anonymous, SEO pages. They carry no session and set no cookies, so the whole
     * session/cookie/CSRF part of the `web` group is stripped — that alone removes two `sessions`
     * queries and the cookie-encryption pass from every hit. SubstituteBindings stays (the slug
     * binding). The caravane list + home also get CachePublicHtmlResponse (full-HTML cache).
     */
    Route::withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
    ])->group(function () {

        Route::middleware(CachePublicHtmlResponse::class)->group(function () {
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

            Route::get('sitemap.xml', [SeoFilesController::class, 'publicWebsiteSitemap'])->name('sitemap');
        });

        Route::get('robots.txt', [SeoFilesController::class, 'publicWebsiteRobots'])->name('robots');

        // Pickup schedule for one départ card, fetched on demand when a visitor expands
        // "Heures de départ" (keeps the caravane page itself free of per-départ schedule queries).
        // Not full-HTML cached: tiny JSON, and its data (heure_departs) is outside the cache
        // invalidation set.
        Route::get('caravanes/horaires/{depart}', [MobileAppController::class, 'caravaneDepartSchedule'])
            ->name('caravanes.schedule');
    });

    // Student booking funnel — collects the passengers then hands the payload to the
    // untouched mobile booking backend. Transactional page: noindex. Keeps the full `web`
    // stack (Livewire needs session + CSRF).
    Route::get('reserver/{depart}', StudentBooking::class)->name('bookings.create');

    // Acceptance of the invitation a sponsoring ambassador sent to a new ambassador. Transactional page: noindex.
    Route::get('devenir-ambassadeur/{invitationToken}', AmbassadorInvitation::class)->name('ambassador-invitation');

    // Public booking page, addressed by the group's shared UUID (not the numeric group_id).
    Route::get('reservations/{uuid}', BookingGroupShow::class)->name('bookings.show');

    // Where Wave sends the customer back after the checkout (see MobileAppController@generatePaymentUrlForMultipleBooking).
    // Same page as the booking, with a payment outcome banner on top.
    Route::get('reservations/{uuid}/paiement/succes', BookingGroupShow::class)
        ->defaults('paymentOutcome', BookingGroupShow::PAYMENT_OUTCOME_SUCCESS)
        ->name('bookings.payment-success');
    Route::get('reservations/{uuid}/paiement/echec', BookingGroupShow::class)
        ->defaults('paymentOutcome', BookingGroupShow::PAYMENT_OUTCOME_ERROR)
        ->name('bookings.payment-error');
});

// Any other host (back office, landing domains): the public website answered above on its own domain.
Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
])->get('robots.txt', [SeoFilesController::class, 'fallbackRobots'])->name('robots.fallback');

Route::get('/', DepartList::class)->middleware(['auth', RejectAmbassadorAccounts::class])->name('home');

Route::middleware(['auth:sanctum', 'verified', RejectAmbassadorAccounts::class])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/profile', Edit::class)->name('profile.edit');
});

/*
 * Back office (Livewire/Flux rewrite). Legacy Vue admin keeps hitting the JSON API;
 * these routes reuse the same controllers and render the data into Flux pages instead.
 */
Route::middleware(['auth', RejectAmbassadorAccounts::class])->prefix('back-office')->name('back-office.')->group(function () {
    Route::get('departs', DepartList::class)->name('departs.index');
    Route::get('departs/create', CreateDepart::class)->name('departs.create');
    Route::get('departs/{depart}/edit', EditDepart::class)->name('departs.edit');
    Route::get('departs/{depart}/cost-report', DepartCostReportPage::class)->name('departs.cost-report');
    Route::get('departs/{depart}/add-bus', AddBusToDepart::class)->name('departs.add-bus');
    Route::get('departs/{depart}/schedule-notifications', DepartScheduleNotifications::class)
        ->name('departs.schedule-notifications');
    Route::get('departs/{depart}/bookings-export', [DepartController::class, 'bookingsForExport'])
        ->name('departs.bookings-export');

    Route::get('point-deps', PointDepList::class)->name('point-deps.index');
    Route::get('itineraires', ItineraireList::class)->name('itineraires.index');
    Route::get('horaires', HoraireList::class)->name('horaires.index');
    Route::get('trajets', TrajetList::class)->name('trajets.index');
    Route::get('ambassadeurs', AmbassadorList::class)->name('ambassadors.index');
    Route::get('ambassadeurs/paiements', AmbassadorPayoutList::class)->name('ambassador-payouts.index');
    Route::get('ambassadeurs/regles-de-recompense', AmbassadorRewardRuleList::class)->name('ambassador-reward-rules.index');
    Route::get('employes', EmployeList::class)->name('employes.index');
    Route::get('users', UserAccessManagement::class)->name('users.index');
    Route::get('vehicules', VehiculeList::class)->name('vehicules.index');
    Route::get('parametres', AppParamsPage::class)->name('parametres.index');

    Route::get('caisses', CaisseBalancesPage::class)->name('caisses.index');
    Route::get('comptes', AccountListPage::class)->name('comptes.index');
    Route::get('cost-reports', DepartCostReportList::class)->name('cost-reports.index');
    Route::get('revenus', ProfitReportPage::class)->name('revenus.index');
    Route::get('depenses', ExpenseManagement::class)->name('depenses.index');
    Route::get('expense-types', ExpenseTypeList::class)->name('expense-types.index');
    Route::get('expense-types/unclassified/operations', ExpenseTypeOperations::class)->name('expense-types.unclassified-operations');
    Route::get('expense-types/{expenseType}/operations', ExpenseTypeOperations::class)->name('expense-types.operations');
    Route::get('paiements-om', OrangeMoneyPage::class)->name('paiements-om.index');
    Route::get('paiements-wave', WavePaymentsPage::class)->name('paiements-wave.index');

    Route::get('buses/{bus}/edit', EditBus::class)->name('buses.edit');
    Route::get('buses/{bus}/bookings', BusBookings::class)->name('buses.bookings');
    Route::get('buses/{bus}/bookings-export', [BusController::class, 'bookingsForExport'])
        ->name('buses.bookings-export');

    Route::get('bookings/{booking}/ticket', [TicketController::class, 'showBookingTicket'])
        ->name('bookings.ticket');

    Route::get('ticket-payments/{ticketPayment}/proofs/{proofIndex}', [PaymentProofController::class, 'show'])
        ->whereNumber('proofIndex')
        ->name('payment-proofs.show');

    Route::post('bookings/{booking}/save_ticket_payment', [BookingController::class, 'saveTicketPayment'])
        ->name('bookings.save-ticket-payment');
    Route::post('bookings/{booking}/trigger_payment_request/{paymentMethod}', [BookingController::class, 'triggerPaymentRequestForPaymentMethod'])
        ->name('bookings.trigger-payment-request');
});
