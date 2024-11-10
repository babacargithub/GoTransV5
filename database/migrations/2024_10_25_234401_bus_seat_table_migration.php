<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        Schema::rename('siege_bus','bus_seats');

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::rename('bus_seats','bus_seat');
    }
};
