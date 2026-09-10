<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trajets', function (Blueprint $table) {
            $table->boolean('disabled')->default(false)->after('slug');
            $table->unsignedInteger('display_position')->default(0)->after('disabled');
        });
    }

    public function down(): void
    {
        Schema::table('trajets', function (Blueprint $table) {
            $table->dropColumn(['disabled', 'display_position']);
        });
    }
};
