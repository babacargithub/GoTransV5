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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string("booked_for_customer")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('bookings', function (Blueprint $table) {
            //check if table has column
           if (Schema::hasColumn('bookings', 'booked_for_customer')) {
               $table->dropColumn('booked_for_customer');
           }

        });
    }
};
