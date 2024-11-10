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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer("amount");
            $table->dateTime("date_start")->nullable();
            $table->dateTime("date_end")->nullable();
            $table->json("bus_ids")->nullable();
            $table->json("depart_ids")->nullable();
            $table->mediumText("condition")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
