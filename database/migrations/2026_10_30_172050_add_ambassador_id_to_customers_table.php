<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // The ambassador who acquired this customer, set once at their first booking and never
            // changed afterwards. Null for customers who came without an ambassador.
            $table->foreignId('ambassador_id')
                ->nullable()
                ->after('customer_category_id')
                ->constrained('ambassadors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ambassador_id');
        });
    }
};
