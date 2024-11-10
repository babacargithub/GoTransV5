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
        Schema::table('depart', function (Blueprint $table) {
            // Drop the foreign key constraint on 'trajet' if it exists
            $table->renameColumn("libelle","name");

            // Rename 'trajet' column to 'trajet_id'
            $table->renameColumn('trajet', 'trajet_id');
            $table->renameColumn('horaire', 'horaire_id');
            $table->renameColumn('event','event_id');
            $table->renameColumn('clos_res', 'closed');
            $table->renameColumn('clos_paye', 'locked');


            // Re-apply the foreign key constraint with the new column name
            $table->foreign('trajet_id')->references('id')->on('trajets')->onDelete('cascade');
            $table->foreign('horaire_id')->references('id')->on('horaires')->onDelete('cascade');
        });
        Schema::rename('depart','departs');
        DB::statement('UPDATE departs SET closed = 0, locked= 0 WHERE event_id = 117');


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::rename('departs','depart');
        Schema::table('depart', function (Blueprint $table) {
            // Drop the foreign key constraint on 'trajet_id' if it exists
            // Rename 'trajet_id' column to 'trajet'
            $table->renameColumn('name', 'libelle');
            $table->renameColumn('trajet_id', 'trajet');
            $table->renameColumn('horaire_id', 'horaire');
            $table->renameColumn('event_id', 'event');
            $table->renameColumn('closed', 'clos_res');
            $table->renameColumn('locked', 'clos_paye');
            $table->timestamps();
            // Re-apply the foreign key constraint with the new column name
            $table->foreign('trajet')->references('id')->on('trajets')->onDelete('cascade');
            $table->foreign('horaire')->references('id')->on('horaires')->onDelete('cascade');
        });

    }
};
