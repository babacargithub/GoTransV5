<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_payouts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ambassador_id')
                ->constrained('ambassadors')
                ->restrictOnDelete();

            // Total sent, in FCFA. Always positive.
            $table->unsignedInteger('amount');

            // Number the money was sent to, copied at request time so later edits do not rewrite history.
            $table->unsignedBigInteger('wave_phone_number');

            $table->string('status', 20)->default('pending')->index();

            // Sent to Wave so that retrying a request can never pay twice.
            $table->uuid('idempotency_key')->unique();

            // Wave's own reference for the payout, known once Wave has accepted the request.
            $table->string('provider_payout_id')->nullable()->unique();
            $table->text('failure_reason')->nullable();

            // The back-office user who requested the payout (legacy `int(11)` users.id, no DB-level FK).
            $table->integer('requested_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_payouts');
    }
};
