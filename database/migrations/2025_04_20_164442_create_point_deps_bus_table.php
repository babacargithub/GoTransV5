<?php

use App\Models\PointDep;
use App\Models\Bus;
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
        Schema::create('point_dep_buses', function (Blueprint $table) {
            $table->id();
            $table->integer("bus_id");
            $table->integer("point_dep_id");
            $table->string("arret_bus")->nullable();
            $table->boolean("disabled")->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_deps_bus');
    }
};
