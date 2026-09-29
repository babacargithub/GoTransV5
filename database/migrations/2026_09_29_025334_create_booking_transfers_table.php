<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_transfers', function (Blueprint $table): void {
            $table->id();

            // Not DB-level foreign keys: bookings/buses/bus_seats.id are legacy `int(11)` columns
            // (pre-Laravel schema), incompatible with Laravel's bigint-unsigned foreignId() — same
            // convention already used elsewhere in this app for such references (e.g.
            // account_transactions.user_id, referencing the equally legacy users.id).
            $table->integer('booking_id')->index();

            $table->integer('source_bus_id')->index();
            $table->integer('target_bus_id')->index();

            // Nullable: an unpaid booking being transferred can be seatless both before and after
            // (seat assignment is deferred to payment confirmation — see BusManager::transferBookings).
            $table->integer('source_seat_id')->nullable();
            $table->integer('target_seat_id')->nullable();

            // The seat *number* at the time of transfer, kept alongside the seat_id columns: a
            // bus_seat row is reused for other bookings once freed, so its current number would no
            // longer reflect what this log entry actually recorded once seats churn.
            $table->string('source_seat_number')->nullable();
            $table->string('target_seat_number')->nullable();

            // INDIVIDUAL (BookingController::transferBooking, one booking) or BULK
            // (BusManager::transferBookings, a whole batch moved together).
            $table->string('transfer_type', 20);

            // The back-office user who performed the transfer. Also a legacy `int(11)` reference
            // (users.id), so plain integer + index, no DB-level foreign key — see above.
            $table->integer('user_id')->nullable()->index();

            $table->timestamp('transferred_at');

            $table->text('comment')->nullable();

            $table->timestamps();

            $table->index(['source_bus_id', 'target_bus_id']);
            $table->index(['booking_id', 'transferred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_transfers');
    }
};
