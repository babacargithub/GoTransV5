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
        Schema::table('heure_departs', function (Blueprint $table) {
            if (!Schema::hasColumn('heure_departs', 'disabled')) {
                $table->boolean('disabled')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('heure_departs', function (Blueprint $table) {
            if (Schema::hasColumn('heure_departs', 'disabled')) {
                $table->dropColumn('disabled');
            }
        });
    }
};
