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
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->string("caller_phone_number")->nullable();
            $table->string("contact_name")->nullable();
            $table->dateTime('called_at')->nullable();
            $table->enum('call_type',["INCOMING","OUTGOING"]);
            $table->enum("status",["ANSWERED", "REJECTED","MISSED"]);
            $table->integer("duration");
            $table->json("details")->nullable();
            $table->foreignId("device_id")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
