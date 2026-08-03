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
        Schema::create('sms_delivery_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('callback_data')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('delivery_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_delivery_receipts');
    }
};
