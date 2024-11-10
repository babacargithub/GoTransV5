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
        Schema::table('evenement', function (Blueprint $table) {
            $table->dropColumn("trajet");

        });
        Schema::rename("evenement","events");

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('events', function (Blueprint $table) {
            $table->integer("trajet")->nullable();
        });
        Schema::rename("events","evenement");
    }
};
