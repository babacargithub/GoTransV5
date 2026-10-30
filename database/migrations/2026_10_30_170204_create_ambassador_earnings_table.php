<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_earnings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ambassador_id')
                ->constrained('ambassadors')
                ->restrictOnDelete();

            // acquisition | sponsorship_bonus
            $table->string('type', 30);

            // FCFA amount, snapshotted when the line is created so later changes to the global rates
            // never rewrite history.
            $table->unsignedInteger('amount');

            // pending | approved | paid | cancelled
            $table->string('status', 20)->default('pending');

            // Natural key that makes creating a line idempotent, e.g. "acquisition:{customer_id}"
            // or "sponsorship:{sponsored_ambassador_id}".
            $table->string('idempotency_key')->unique();

            // The booking this line stems from (the customer is reached through it). Legacy `int(11)`
            // bookings.id, so a plain integer without a DB-level foreign key. Null for a sponsorship
            // bonus, which concerns a sponsored ambassador instead.
            $table->integer('booking_id')->nullable()->index();

            // For a sponsorship bonus: the sponsored ambassador who reached the threshold.
            $table->foreignId('source_ambassador_id')
                ->nullable()
                ->constrained('ambassadors')
                ->nullOnDelete();

            $table->string('label')->nullable();

            // When a pending line becomes payable (24h after the departure was completed).
            $table->timestamp('approvable_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('ambassador_payout_id')
                ->nullable()
                ->constrained('ambassador_payouts')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['ambassador_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_earnings');
    }
};
