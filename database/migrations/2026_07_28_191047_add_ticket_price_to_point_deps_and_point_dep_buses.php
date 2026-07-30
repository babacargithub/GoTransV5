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
            $table->integer('ticket_price')->nullable()->after('city');
        });

        Schema::table('point_dep_buses', function (Blueprint $table) {
            $table->integer('ticket_price')->nullable()->after('arret_bus');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_deps', function (Blueprint $table) {
            if (Schema::hasColumn('point_deps', 'ticket_price')) {
                $table->dropColumn('ticket_price');
            }
        });

        Schema::table('point_dep_buses', function (Blueprint $table) {
            if (Schema::hasColumn('point_dep_buses', 'ticket_price')) {
                $table->dropColumn('ticket_price');
            }
        });
    }
};
