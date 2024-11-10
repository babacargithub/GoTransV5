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
        Schema::rename('trajet','trajets');
        // add column start_point
        Schema::table('trajets', function (Blueprint $table) {
            $table->string('start_point')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::rename('trajets','trajet');
        Schema::table('trajet', function (Blueprint $table) {
            $table->dropColumn('start_point');
        });
    }
};
