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
        Schema::create('promotional_messages', function (Blueprint $table) {
            $table->id();
            $table->dateTime("date_start")->nullable();
            $table->dateTime("date_end")->nullable(false);
            $table->json("bus_ids")->nullable();
            $table->json("depart_ids")->nullable();
            $table->mediumText("message")->nullable();
            $table->json("data")->nullable();
            $table->boolean("paused")->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotional_message');
    }
};
