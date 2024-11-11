<?php

namespace App\Console\Commands;

use App\Models\AppParams;
use App\Models\Horaire;
use App\Models\Trajet;
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
                'api_endpoint' => 'https://golobone.net/go_travel_v5/public/api',
                'app_description' => 'Systeme de réservation de ticket sur les lignes de bus de Golob Transport',
                'app_name' => 'Golob Transport',
                'front_endpoint' => 'https://globeone.site',
                'main_customer_service_number' => '771273535',
                'minimum_version' => '1.0.0',
                'second_customer_service_number' => '771163003',
                'server_endpoint' => 'https://golobone.net/go_travel_v5/public/',
                'third_customer_service_number' => '771271212',
                'trajets' => [
                    ['name' => 'UGB vers DAKAR', 'id' => 1],
                    ['name' => 'DAKAR vers UGB', 'id' => 2]
                ],
                "discount_price" => 500,
                "discount_condition" => "more_than_1_ticket",
                'warning_message_before_booking' => 'Avant de valider, assurez-vous d\'avoir la somme {ticketPrice} FCFA dans son compte {payment_method}, sinon la réservation ne sera pas confirmée. \nLe Ticket n\'est pas remboursable !'
            ];
            $AppParams->save();
        }
        $trajet = Trajet::find(1);
        $trajet->start_point = "Campus UGB Village P";
        $trajet->end_point = "Rond-Point  Cambrène";
        $trajet->save();
        $trajet = Trajet::find(2);
        $trajet->end_point = "Campus UGB Village P";
        $trajet->start_point = "École Normale";
        $trajet->save();

        $horaire = Horaire::find(1);
        $horaire->periode = "matin";
        $horaire->save();
        $horaire = Horaire::find(2);
        $horaire->periode = "apres-midi";
        $horaire->save();
        $horaire = Horaire::find(3);
        $horaire->periode = "nuit";
        $horaire->save();
        $horaire = Horaire::find(4);
        $horaire->periode = "matin";
        $horaire->save();
        $horaire = Horaire::find(5);
        $horaire->periode = "apres-midi";
        $horaire->save();
        $horaire = Horaire::find(6);
        $horaire->periode = "nuit";
        $horaire->save();

        // output console message
        $this->info('Old database updated successfully');




    }
}
