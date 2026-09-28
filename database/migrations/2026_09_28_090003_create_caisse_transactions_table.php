<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisse_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('caisse_id')->constrained()->cascadeOnDelete();

            // The back-office user who initiated this transaction. Null for
            // fully-automatic transactions with no staff actor (e.g. a caisse
            // deposit triggered by an online Wave/OM payment webhook).
            // Not a DB-level foreign key: users.id is a legacy `int(11)` column
            // (pre-Laravel schema), incompatible with Laravel's bigint-unsigned
            // foreignId() — same convention already used elsewhere in this app
            // for user references (e.g. tickets.created_by / updated_by).
            $table->integer('user_id')->nullable()->index();

            // Always stored as a positive integer.
            $table->integer('amount');

            $table->string('label')->nullable();
            $table->string('transaction_type');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caisse_transactions');
    }
};
