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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('round_trip_id')->nullable()->after('group_id');
            $table->string('trip_leg')->nullable()->after('round_trip_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'trip_leg')) {
                $table->dropColumn('trip_leg');
            }
            if (Schema::hasColumn('bookings', 'round_trip_id')) {
                $table->dropColumn('round_trip_id');
            }
        });
    }
};
