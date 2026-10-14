<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `position` orders a trajet's destinations along the route: the higher, the farther from the
     * start. Existing rows are seeded from their id (creation order) and can be adjusted afterwards.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('trajet_id');
        });

        DB::table('destinations')->update(['position' => DB::raw('id')]);
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
