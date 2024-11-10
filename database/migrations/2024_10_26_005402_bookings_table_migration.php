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
        Schema::table('reservation', function (Blueprint $table) {
            $table->renameColumn("date","created_at");
            $table->renameColumn("seat","seat_id");
            $table->renameColumn("ticket","ticket_id");
            $table->renameColumn("bus","bus_id");
            $table->renameColumn("client","customer_id");
            $table->renameColumn("depart","depart_id");
            $table->renameColumn("point_dep","point_dep_id");
            $table->renameColumn("des","destination_id");
            $table->renameColumn("agent","employe_id");
            $table->dateTime('updated_at')->nullable();
        });
        Schema::rename('reservation','bookings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('bookings', function (Blueprint $table) {
            $table->renameColumn("created_at","date");
            $table->renameColumn("seat_id","seat");
            $table->renameColumn("ticket_id","ticket");
            $table->renameColumn("bus_id","bus");
            $table->renameColumn("customer_id","client");
            $table->renameColumn("depart_id","depart");
            $table->renameColumn("point_dep_id","point_dep");
            $table->renameColumn("destination_id","des");
            $table->renameColumn("employe_id","agent");
        });
        Schema::rename('bookings','reservation');
    }
};
