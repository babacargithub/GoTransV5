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
        Schema::rename('horaire','horaires');
        Schema::table('horaires', function (Blueprint $table) {
            $table->renameColumn('departure_time',"bus_leave_time");
            $table->string('constant_name')->nullable()->change();
            $table->enum("periode",["matin","apres-midi","nuit"])->nullable();

        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::rename('horaires','horaire');
        Schema::table('horaire', function (Blueprint $table) {
            $table->renameColumn('bus_leave_time',"departure_time");
            $table->dropColumn('constant_name');
        });
    }
};
