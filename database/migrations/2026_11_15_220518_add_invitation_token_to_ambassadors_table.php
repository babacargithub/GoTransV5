<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassadors', function (Blueprint $table): void {
            // Secret carried by the link a sponsor sends to the ambassador they invite. Only set while the
            // invitation is pending acceptance; cleared once accepted.
            $table->string('invitation_token', 64)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('ambassadors', function (Blueprint $table): void {
            $table->dropUnique(['invitation_token']);
            $table->dropColumn('invitation_token');
        });
    }
};
