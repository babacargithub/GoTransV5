<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_transactions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            // The back-office user who initiated this transaction. Null for
            // fully-automatic transactions with no staff actor (e.g. a caisse
            // deposit triggered by an online Wave/OM payment webhook).
            // Not a DB-level foreign key: users.id is a legacy `int(11)` column
            // (pre-Laravel schema), incompatible with Laravel's bigint-unsigned
            // foreignId() — same convention already used elsewhere in this app
            // for user references (e.g. tickets.created_by / updated_by).
            $table->integer('user_id')->nullable()->index();

            // Always stored as a positive integer.
            $table->unsignedInteger('amount');

            // CREDIT (money in) or DEBIT (money out).
            $table->string('transaction_type', 10);

            $table->string('label')->nullable();

            // Describes what triggered this transaction, e.g. TICKET_SALE | ENTREE_DE_CAISSE | SORTIE_DE_CAISSE | MANUAL.
            $table->string('reference_type', 50)->nullable();

            // ID of the triggering record (e.g. tickets.id).
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'transaction_type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transactions');
    }
};
