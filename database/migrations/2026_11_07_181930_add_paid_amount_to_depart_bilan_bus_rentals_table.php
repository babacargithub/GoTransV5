<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depart_bilan_bus_rentals', function (Blueprint $table): void {
            // Part of rental_fee already paid out of the caisses ("Enregistrer en caisse"), in FCFA.
            $table->unsignedInteger('paid_amount')->default(0)->after('rental_fee');
        });
    }

    public function down(): void
    {
        Schema::table('depart_bilan_bus_rentals', function (Blueprint $table): void {
            $table->dropColumn('paid_amount');
        });
    }
};
