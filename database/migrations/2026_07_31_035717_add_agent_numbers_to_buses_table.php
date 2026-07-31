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
            // Contact number(s) of the field agent (convoyeur) supervising this bus, so waiting
            // customers can call for info. Multiple numbers are separated by "/" (e.g. "77xxxxxxx/78xxxxxxx").
            $table->string('agent_numbers')->nullable()->after('closed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            if (Schema::hasColumn('buses', 'agent_numbers')) {
                $table->dropColumn('agent_numbers');
            }
        });
    }
};
