<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassador_earnings', function (Blueprint $table): void {
            // Who approved the line by hand before its waiting period was over (legacy `int(11)` users.id,
            // so no DB-level foreign key), and why.
            $table->integer('approved_by_user_id')->nullable()->after('approved_at');
            $table->string('early_approval_reason')->nullable()->after('approved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_earnings', function (Blueprint $table): void {
            $table->dropColumn(['approved_by_user_id', 'early_approval_reason']);
        });
    }
};
