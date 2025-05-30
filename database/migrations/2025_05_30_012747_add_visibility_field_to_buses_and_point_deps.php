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
        Schema::table('point_deps', function (Blueprint $table) {
            //
            if (!Schema::hasColumn('point_deps', 'visibilite')) {
                $table->integer('visibilite')->default(1);
            }
        });
        Schema::table('buses', function (Blueprint $table) {
            //
            if (!Schema::hasColumn('buses', 'visibilite')) {
                $table->integer('visibilite')->default(1);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::table('point_deps', function (Blueprint $table) {
            //
            if (Schema::hasColumn('point_deps', 'visibilite')) {
                $table->dropColumn('visibilite');
            }
        });
        Schema::table('buses', function (Blueprint $table) {
            //
            if (Schema::hasColumn('buses', 'visibilite')) {
                $table->dropColumn('visibilite');
            }
        });
    }
};
