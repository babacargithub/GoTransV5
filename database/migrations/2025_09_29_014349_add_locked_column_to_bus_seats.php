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
        Schema::table('bus_seats', function (Blueprint $table) {
            //
            Schema::table('bus_seats', function (Blueprint $table) {
                if (!Schema::hasColumn('bus_seats', 'locked')) {
                    $table->boolean('locked')->default(false);
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bus_seats', function (Blueprint $table) {
            //
            if (Schema::hasColumn('bus_seats', 'locked')) {
                $table->dropColumn('locked');

            }
        });
    }
};
