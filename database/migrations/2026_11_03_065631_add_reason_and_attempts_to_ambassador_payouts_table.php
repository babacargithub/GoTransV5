<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassador_payouts', function (Blueprint $table): void {
            // Message the ambassador sees on their Wave statement.
            $table->string('payment_reason')->nullable()->after('wave_phone_number');

            // How many times the request was sent to Wave, to cap retries.
            $table->unsignedTinyInteger('attempts_count')->default(0)->after('failure_reason');
            $table->timestamp('last_attempted_at')->nullable()->after('attempts_count');
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_payouts', function (Blueprint $table): void {
            $table->dropColumn(['payment_reason', 'attempts_count', 'last_attempted_at']);
        });
    }
};
