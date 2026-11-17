<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_sms_messages', function (Blueprint $table): void {
            $table->id();

            // International format without "+", e.g. 221771234567.
            $table->string('recipient_phone_number', 20)->index();
            $table->text('content');

            $table->enum('status', ['PENDING', 'SENT', 'FAILED', 'SKIPPED'])->default('PENDING')->index();

            // Orange's own reference for the accepted request, to match delivery receipts later.
            $table->string('provider_resource_url')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_sms_messages');
    }
};
