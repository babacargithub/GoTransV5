<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_abilities', function (Blueprint $table): void {
            $table->id();

            // Stable internal identifier (e.g. "sponsor"); the label is the French wording managers see.
            $table->string('code')->unique();
            $table->string('label');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_abilities');
    }
};
