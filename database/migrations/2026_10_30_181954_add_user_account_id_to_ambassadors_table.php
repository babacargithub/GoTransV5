<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassadors', function (Blueprint $table): void {
            // users.id is a legacy int(11), so no DB foreign key (see the convention in create_ambassadors_table).
            // Unique: one login account per ambassador.
            $table->integer('user_account_id')->nullable()->unique()->after('sponsor_ambassador_id');
        });
    }

    public function down(): void
    {
        Schema::table('ambassadors', function (Blueprint $table): void {
            $table->dropUnique(['user_account_id']);
            $table->dropColumn('user_account_id');
        });
    }
};
