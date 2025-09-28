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
        Schema::table('trajets', function (Blueprint $table) {
            $table->string('departure_city')->nullable()->after('name');
            $table->string('arrival_city')->nullable()->after('departure_city');
            $table->string('code')->nullable()->unique()->after('arrival_city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trajets', function (Blueprint $table) {
            $table->dropColumn(['departure_city', 'arrival_city', 'code']);
        });
    }
};
