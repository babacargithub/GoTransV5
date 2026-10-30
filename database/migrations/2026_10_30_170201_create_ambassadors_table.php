<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassadors', function (Blueprint $table): void {
            $table->id();

            // Parrainage: the ambassador who created this one. One level only — earnings never look
            // further up than the direct sponsor.
            $table->foreignId('sponsor_ambassador_id')
                ->nullable()
                ->constrained('ambassadors')
                ->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100);

            // Same shape as customers.phone_number (bigint, unique) so both can be compared directly
            // for self-referral checks.
            $table->unsignedBigInteger('phone_number')->unique();

            // Wave number payouts are sent to. Falls back to phone_number when null.
            $table->unsignedBigInteger('wave_phone_number')->nullable();

            // Code typed by customers or carried by the ?ref= link, stored upper-cased.
            $table->string('code', 20)->unique();

            $table->string('status', 30)->default('pending_approval')->index();

            // Every ambassador works under a contract. After this date the code stops attributing
            // new customers; amounts already earned stay payable.
            $table->date('contract_starts_at')->nullable();
            $table->date('contract_ends_at')->nullable()->index();

            // The approving back-office user. Legacy `int(11)` users.id, so plain integer without a
            // DB-level foreign key (same convention as account_transactions.user_id).
            $table->integer('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassadors');
    }
};
