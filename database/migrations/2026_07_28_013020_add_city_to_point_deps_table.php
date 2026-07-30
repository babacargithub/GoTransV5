<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('point_deps', function (Blueprint $table) {
            $table->string('city')->nullable()->after('name');
        });

        // Mark the existing mid-route Thies stop (trajet 2, DAKAR->SAINT-LOUIS) as a searchable city.
        DB::table('point_deps')
            ->where('name', 'Thies')
            ->where('trajet_id', 2)
            ->update(['city' => 'THIES']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_deps', function (Blueprint $table) {
            if (Schema::hasColumn('point_deps', 'city')) {
                $table->dropColumn('city');
            }
        });
    }
};
