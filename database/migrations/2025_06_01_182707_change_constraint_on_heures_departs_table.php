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
        Schema::table('heure_departs', function (Blueprint $table) {
            $table->dropUnique('point_dep_unique');
            // add unique on depart_id, bus_id and point_dep_id
            $table->unique(['depart_id', 'bus_id', 'point_dep_id'], 'unique_depart_bus_point_dep');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('heure_departs', function (Blueprint $table) {
            $table->dropUnique(['unique_depart_bus_point_dep']);
            // add unique on point_dep_unique
            $table->unique('point_dep_unique');
        });
    }
};
