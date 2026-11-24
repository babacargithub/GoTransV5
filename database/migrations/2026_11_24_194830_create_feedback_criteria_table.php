<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_criteria', function (Blueprint $table): void {
            $table->id();

            // Stable internal identifier (e.g. "driver"); the label is the French wording agents and managers see.
            $table->string('code')->unique();
            $table->string('label');
            $table->unsignedSmallInteger('position')->default(0);

            // Retired criteria stay in the table so past ratings keep their meaning.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_criteria');
    }
};
