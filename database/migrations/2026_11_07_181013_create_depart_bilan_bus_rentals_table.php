<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depart_bilan_bus_rentals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('depart_bilan_id')->constrained()->cascadeOnDelete();

            // buses.id is a legacy signed int(11), so foreignId() cannot reference it.
            $table->integer('bus_id');
            $table->foreign('bus_id')->references('id')->on('buses')->cascadeOnDelete();

            $table->string('bus_owner_name');

            // Rental fee paid to the bus owner, in FCFA.
            $table->unsignedInteger('rental_fee');

            $table->timestamps();

            $table->unique(['depart_bilan_id', 'bus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depart_bilan_bus_rentals');
    }
};
