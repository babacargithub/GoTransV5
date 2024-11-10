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
        Schema::table('heure_depart', function (Blueprint $table) {
            $table->renameColumn("depart","depart_id");
            $table->foreign('depart_id')->references('id')->on('departs')->onDelete('cascade');
            $table->renameColumn("point_dep","point_dep_id");
            $table->foreign('point_dep_id')->references('id')->on('point_deps')->onDelete('cascade');

        });
        Schema::rename('heure_depart','heure_departs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('heure_departs', function (Blueprint $table) {
            $table->renameColumn("depart_id","depart");
            $table->renameColumn("point_dep_id","point_dep");
        });
        Schema::rename('heure_departs','heure_depart');
    }
};
