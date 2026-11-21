<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassador_earnings', function (Blueprint $table): void {
            // Link to the post or video that justifies a manually recorded promotion reward.
            $table->string('proof_url', 500)->nullable()->after('label');

            // Who recorded a promotion reward by hand (legacy `int(11)` users.id, so no DB-level foreign key).
            $table->integer('recorded_by_user_id')->nullable()->after('proof_url');
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_earnings', function (Blueprint $table): void {
            $table->dropColumn(['proof_url', 'recorded_by_user_id']);
        });
    }
};
