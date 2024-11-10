<?php

namespace App\Http\Controllers;

use App\Models\Employe;
use Illuminate\Http\Request;

class EmployeController extends Controller
{
    //

    public function index()
    {
        $employes = Employe::all();
        /**
         * {
         * "@type": "Employee",
         * "@id": "/api/employees/5",
         * "phoneNumber": 773300853,
         * "email": "pdggolobone@gmail.com",
         * "address": "114L",
         * "jobTitle": "1",
         * "active": true,
         * "disabled": true,
         * "canSellTicket": true,
         * "canCancelPaidBooking": true,
         * "canChooseSeats": true,
         * "gender": "M",
         * "fullName": "Babacar SEYE",
         * "ticketSaleAccount": {
         * "@id": "/api/ticket_sale_accounts/1",
         * "@type": "TicketSaleAccount",
         * "owner": "/api/employees/5",
         * "cashBacks": [
         * "/api/main_account_cash_backs/3",
         * "/api/main_account_cash_backs/8",
         * "/api/main_account_cash_backs/10",
         * "/api/main_account_cash_backs/13"
         * ],
         * "number": 1234567,
         * "enabled": true,
         * "lastActive": "2021-04-30T00:00:00+00:00",
         * "balance": 766213,
         * "id": 1,
         * "createdAt": "2021-05-01T00:00:00+00:00",
         * "updatedAt": "2024-08-17T16:37:00+00:00",
         * "deleted": false,
         * "updatedBy": "babacar"
         * },
         * "id": 5
         * }
         */
        return response()->json(
            $employes->map(function ($employe) {
                return [
                    "@type" => "Employee",
                    "@id" => "/api/employees/" . $employe->id,
                    "phoneNumber" => $employe->tel,
                    "fullName" => $employe->nom . " " . $employe->prenom,
                    "email" => $employe->email,
                    "address" => $employe->adresse,
                    "gender" => $employe->sexe,
                    "jobTitle" => $employe->jobTitle,
                    "active" => $employe->actif,
                    "disabled" => $employe->actif,
                    "canSellTicket" => $employe->can_sell_ticket,
                    "canCancelPaidBooking" => $employe->can_cancel_paid_booking,
                    "canChooseSeats" => $employe->can_choose_seats];
            }));
    }
}
