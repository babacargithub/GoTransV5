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
        Schema::table('bus', function (Blueprint $table) {
            // Drop the foreign key constraint on 'vehicule' if it exists
            $table->renameColumn("nom","name");
            // Rename 'vehicule' column to 'vehicule_id'
            $table->renameColumn('vehicule', 'vehicule_id');
            $table->renameColumn('depart', 'depart_id');

            // Re-apply the foreign key constraint with the new column name
            $table->foreign('vehicule_id')->references('id')->on('vehicules')->onDelete('cascade');
        });
        Schema::rename('bus','buses');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('buses', function (Blueprint $table) {
            $table->renameColumn('name', 'nom');
            $table->renameColumn('vehicule_id', 'vehicule');
            $table->renameColumn('depart_id', 'depart');

        });

        Schema::rename('buses','bus');

    }
};
