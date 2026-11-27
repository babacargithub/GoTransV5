<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // The amicale whose website the booking was made on. Never set together with ambassador_id.
            $table->foreignId('amicale_id')
                ->nullable()
                ->after('ambassador_id')
                ->constrained('amicales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('amicale_id');
        });
    }
};
