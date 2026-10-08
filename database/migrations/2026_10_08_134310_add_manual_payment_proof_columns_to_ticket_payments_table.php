<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_id')->nullable()->index();
            $table->string('provider_transaction_id')->nullable()->unique();
            $table->json('proofs')->nullable();
            $table->text('proof_note')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->dropIndex(['ticket_id']);
            $table->dropUnique(['provider_transaction_id']);
            $table->dropColumn(['ticket_id', 'provider_transaction_id', 'proofs', 'proof_note', 'recorded_by_user_id']);
        });
    }
};
