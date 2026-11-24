<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depart_feedbacks', function (Blueprint $table): void {
            $table->id();

            // Legacy tables use signed int(11) ids, so the foreign keys are declared as plain integers.
            $table->integer('depart_id');
            $table->integer('customer_id');

            // The booking this call is about; one feedback per booking, created when the depart is marked arrived.
            $table->integer('booking_id')->unique();

            // Null until the agent records how the call went. NoAnswer / Unreachable stay in the "to call back" list.
            $table->string('outcome')->nullable();

            // The agent who took the card. A claim older than the expiry is considered released.
            $table->integer('claimed_by_user_id')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamp('called_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('comment')->nullable();

            // Private disk path of the call recording; never served publicly.
            $table->string('recording_path')->nullable();
            $table->timestamp('recording_uploaded_at')->nullable();

            // Audit of the last time a manager listened to the recording.
            $table->timestamp('recording_last_listened_at')->nullable();
            $table->integer('recording_last_listened_by')->nullable();

            $table->timestamps();

            $table->foreign('depart_id')->references('id')->on('departs')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('claimed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('recording_last_listened_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['depart_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depart_feedbacks');
    }
};
