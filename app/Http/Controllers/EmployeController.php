<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\Device;
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

    public function registerPhoneCallLogs(Request $request)
    {
        // the purpose of this function is to register call logs coming customer service phone calls used by employees
        $data = $request->input('call_logs');
        \Log::info("call logs content body", $request->all());
        // Save call logs to laravel.log
        \Log::info("call logs", (array) $data);

        return response()->json(['message' => 'Call logs registered successfully']);


    }
    public function logNewCall(Request $request)
    {
        // the purpose of this function is to register call logs coming customer service phone calls used by employees
        $data = $request->all();
        //content body {"callDate":"Mar 8, 2025 8:56:57 PM","callType":"INCOMING","contactName":"Unknown","duration":47,"phoneNumber":"+221770708208"}
        $callLogEntry = new CallLog();
        $callLogEntry->device_id = Device::where("device_id",$data["deviceId"])->first()?->id ??"Unknown";
        $callLogEntry->called_at = $data["callDate"];
        $callLogEntry->call_type = $data["callType"];
        $callLogEntry->contact_name = $data["contactName"];
        $callLogEntry->duration = $data["duration"];
        $callLogEntry->caller_phone_number = $data["phoneNumber"];
        $callLogEntry->status = $data["status"];
        $callLogEntry->details = json_encode($data);

        $callLogEntry->save();
        
        \Log::info("New call received:  content body", $request->all());
        // Save call logs to laravel.log
        \Log::info("new call", (array) $data);

        return response()->json(['message' => 'Call logs registered successfully']);


    }
}
