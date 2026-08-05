<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('group_id');
        });

        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
        });

        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
        });
    }
};
