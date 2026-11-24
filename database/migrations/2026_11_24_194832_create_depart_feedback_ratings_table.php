<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depart_feedback_ratings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('depart_feedback_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_criterion_id')->constrained()->restrictOnDelete();

            // 1 (very bad) to 5 (excellent).
            $table->unsignedTinyInteger('score');

            $table->timestamps();

            $table->unique(['depart_feedback_id', 'feedback_criterion_id'], 'depart_feedback_ratings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depart_feedback_ratings');
    }
};
