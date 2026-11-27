<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshot_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // users.id is a legacy int(11), so no DB foreign key (same convention as amicales.user_account_id).
            $table->integer('user_id')->index();
            $table->string('note')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('file_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshot_batches');
    }
};
