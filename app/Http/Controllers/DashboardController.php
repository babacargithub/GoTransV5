<?php

namespace App\Http\Controllers;

use App\Data\Dashboard\DashboardDTO;
use App\Enums\PermissionName;
use App\Models\Trajet;
use App\Services\AmbassadorPerformanceService;
use App\Services\BookingService;
use App\Services\BusService;
use App\Services\CustomerService;
use App\Services\DepartService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Back-office dashboard: assembles the figures of the selected period (default: current month), optionally
 * narrowed to one trajet. All counting is done by the dedicated services.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly BookingService $bookingService,
        private readonly DepartService $departService,
        private readonly BusService $busService,
        private readonly AmbassadorPerformanceService $ambassadorPerformanceService,
    ) {}

    public function index(Request $request): View
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'trajet_id' => ['nullable', 'integer', 'exists:trajets,id'],
        ]);

        $periodStart = isset($validated['date_from']) ? now()->parse($validated['date_from'])->startOfDay() : now()->startOfMonth();
        $periodEnd = isset($validated['date_to']) ? now()->parse($validated['date_to'])->endOfDay() : now()->endOfMonth();
        $trajet = isset($validated['trajet_id']) ? Trajet::query()->find($validated['trajet_id']) : null;

        $dashboard = new DashboardDTO(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            trajetId: $trajet?->id,
            newCustomersCount: $this->customerService->countNewCustomers($periodStart, $periodEnd, $trajet),
            newCustomersBroughtByAmbassadorsCount: $this->customerService->countNewCustomersBroughtByAmbassadors($periodStart, $periodEnd, $trajet),
            bookingsCount: $this->bookingService->countBookings($periodStart, $periodEnd, $trajet),
            bookingsBroughtByAmbassadorsCount: $this->bookingService->countBookingsBroughtByAmbassadors($periodStart, $periodEnd, $trajet),
            departsCount: $this->departService->countDeparts($periodStart, $periodEnd, $trajet),
            busesCount: $this->busService->countBuses($periodStart, $periodEnd, $trajet),
            amountDueToAmbassadors: $this->ambassadorPerformanceService->getAllAmbassadorsBalance($periodStart, $periodEnd, $trajet),
            bookingsPerDay: $this->bookingService->countBookingsPerDay($periodStart, $periodEnd, $trajet),
            newCustomersPerDay: $this->customerService->countNewCustomersPerDay($periodStart, $periodEnd, $trajet),
        );

        return view('back-office.dashboard', [
            'dashboard' => $dashboard,
            'trajetOptions' => Trajet::query()->orderBy('name')->get(['id', 'name', 'public_name']),
        ]);
    }
}
