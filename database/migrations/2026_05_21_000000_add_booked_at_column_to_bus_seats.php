<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_seats', function (Blueprint $table) {
            if (!Schema::hasColumn('bus_seats', 'booked_at')) {
                $table->timestamp('booked_at')->nullable()->after('booked');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bus_seats', function (Blueprint $table) {
            if (Schema::hasColumn('bus_seats', 'booked_at')) {
                $table->dropColumn('booked_at');
            }
        });
    }
};
