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
        Schema::rename('vehicule','vehicules');
        Schema::table('vehicules', function (Blueprint $table) {
            $table->boolean('default')->default(false)->nullable();
            $table->renameColumn('nombrePlace','nombre_place');
            $table->renameColumn("nom","name");
            $table->renameColumn("type","vehicule_type");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::rename('vehicules','vehicule');
        Schema::table('vehicule', function (Blueprint $table) {
            $table->dropColumn('default');
            $table->renameColumn('nombre_place','nombrePlace');
            $table->renameColumn("vehicule_type","type");
        });
    }
};
