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
        Schema::table('point_depart', function (Blueprint $table) {
            $table->renameColumn('nom', 'name');

            // rename trajet column to trajet_id
            $table->renameColumn('trajet', 'trajet_id');
            // re-apply the foreign key constraint with the new column name
            $table->boolean("disabled")->default(false)->change();
            $table->foreign('trajet_id')->references('id')->on('trajets')->onDelete('cascade');
        });
        Schema::rename('point_depart','point_deps');

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('point_deps', function (Blueprint $table) {
            $table->renameColumn('trajet_id', 'trajet');
            $table->renameColumn('name', 'nom');
        });
        Schema::rename('point_deps','point_depart');
    }
};
