<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            // Adding the gp_ticket_price column to the buses table
            if (!Schema::hasColumn('buses', 'gp_ticket_price')) {
                $table->integer('gp_ticket_price')->nullable()->default(4150);
            }
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            //
            // Dropping the gp_ticket_price column from the buses table
            if (Schema::hasColumn('buses', 'gp_ticket_price')) {
                $table->dropColumn('gp_ticket_price');
            }
        });
    }
};
