<?php

namespace App\Console\Commands;

use App\Models\AppParams;
use App\Models\User;
use Hash;
use Illuminate\Console\Command;

class MigrateOldDatabaseToNew extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-old-database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update old database to new database';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        //
        $users = User::all();
        foreach ($users as $user) {
            $user->password = Hash::make("0000");
            $user->save();
        }
        //create app params
        if (!AppParams::first()) {
            $AppParams = new AppParams();
            $AppParams->data = [
                'api_endpoint' => 'https://golobone.net/go_travel_v4/public/api',
                'app_description' => 'Systeme de réservation de ticket sur les lignes de bus de Golob Transport',
                'app_name' => 'Golob Transport',
                'front_endpoint' => 'https://globeone.site',
                'main_customer_service_number' => '771273535',
                'minimum_version' => '1.0.0',
                'second_customer_service_number' => '771163003',
                'server_endpoint' => 'https://golobone.net/go_travel_v4/public/',
                'third_customer_service_number' => '777764491',
                'trajets' => [
                    ['name' => 'UGB vers DAKAR', 'id' => 1],
                    ['name' => 'DAKAR vers UGB', 'id' => 2]
                ],
                "discount_price" => 500,
                "discount_condition" => "more_than_1_ticket",
                'warning_message_before_booking' => 'Avant de valider, assurez-vous d\'avoir la somme {ticketPrice} FCFA dans son compte {payment_method}, sinon la réservation ne sera pas enregistrée. \nLe Ticket n\'est pas remboursable !'
            ];
            $AppParams->save();
        }




    }
}
