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
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->text("text")->nullable(false);
            $table->string("to")->nullable(false);
            $table->string("from")->nullable();
            $table->enum("status",["PENDING","PROCESSING","SENT","FAILED"]);
            $table->dateTime("sent_at")->nullable();
            $table->foreignId("device_id")->nullable();
            $table->json("details")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
