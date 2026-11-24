<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departs', function (Blueprint $table): void {
            // Set by the manual "Marquer comme arrivé" action; it is what opens the depart to feedback calls.
            $table->timestamp('arrived_at')->nullable();
            // Legacy tables use signed int(11) ids, so the foreign keys are declared as plain integers.
            $table->integer('arrived_marked_by')->nullable();
            $table->foreign('arrived_marked_by')->references('id')->on('users')->nullOnDelete();

            // Free-text "what to improve" conclusion shown at the end of the feedback report (display only).
            $table->text('feedback_conclusion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departs', function (Blueprint $table): void {
            $table->dropForeign(['arrived_marked_by']);
            $table->dropColumn('arrived_marked_by');
            $table->dropColumn(['arrived_at', 'feedback_conclusion']);
        });
    }
};
