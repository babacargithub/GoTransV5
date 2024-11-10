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
        //
        Schema::table('destination', function (Blueprint $table) {
            // rename trajet column to trajet_id
            $table->renameColumn('libelle', 'name');
            $table->renameColumn('trajet', 'trajet_id');
            // re-apply the foreign key constraint with the new column name
            $table->foreign('trajet_id')
                ->references('id')
                ->on('trajets')
                ->onDelete('cascade');


        });
        Schema::rename('destination','destinations');

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('destinations', function (Blueprint $table) {
            $table->renameColumn('trajet_id', 'trajet');
            $table->renameColumn('name', 'libelle');
        });
        Schema::rename('destinations','destination');
    }
};
