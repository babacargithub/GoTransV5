<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy referer_id values (0 by default) never meant anything, and a foreign key cannot
        // reference them, so they are discarded rather than carried over.
        DB::table('bookings')->whereNotNull('referer_id')->update(['referer_id' => null]);

        Schema::table('bookings', function (Blueprint $table): void {
            $table->renameColumn('referer_id', 'ambassador_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            // Same type as ambassadors.id (unsigned bigint) so it can carry a foreign key.
            $table->unsignedBigInteger('ambassador_id')->nullable()->change();

            $table->foreign('ambassador_id')
                ->references('id')
                ->on('ambassadors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['ambassador_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->bigInteger('ambassador_id')->nullable()->change();
            $table->renameColumn('ambassador_id', 'referer_id');
        });
    }
};
