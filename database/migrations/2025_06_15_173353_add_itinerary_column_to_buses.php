<?php

use App\Models\Itinerary;
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
            $table->foreignIdFor(Itinerary::class)->nullable()->constrained()->cascadeOnUpdate()
                ->cascadeOnDelete();
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            if (Schema::hasColumn("buses","itinerary_id")){
                $table->dropColumn("itinerary_id");
            }

            //
        });
    }
};
