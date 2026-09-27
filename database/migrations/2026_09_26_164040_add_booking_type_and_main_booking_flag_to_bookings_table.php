<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfill rule: a group_id is a real group when it holds more than one passenger, i.e. more
     * than one row once the return legs of round trips are left out (a lone traveller's round trip
     * is two rows for one passenger). The group's main booking is its lowest id, which is the first
     * passenger since bookings of one purchase are inserted in order. Cancelled (soft-deleted) rows
     * count, so a group that later lost a member keeps its type.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('booking_type', 20)->default('single')->after('group_id');
            $table->boolean('is_main_booking')->default(false)->after('booking_type');
        });

        DB::statement("
            UPDATE bookings
            INNER JOIN (
                SELECT group_id,
                       MIN(id) AS main_booking_id,
                       COUNT(*) - SUM(CASE WHEN trip_leg = 'return' THEN 1 ELSE 0 END) AS passenger_count
                FROM bookings
                WHERE group_id IS NOT NULL
                GROUP BY group_id
            ) AS booking_groups ON booking_groups.group_id = bookings.group_id
            SET bookings.booking_type = 'group',
                bookings.is_main_booking = (bookings.id = booking_groups.main_booking_id)
            WHERE booking_groups.passenger_count > 1
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['booking_type', 'is_main_booking']);
        });
    }
};
