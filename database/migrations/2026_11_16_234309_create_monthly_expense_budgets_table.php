<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_expense_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_type_id')->constrained('expense_types')->cascadeOnDelete();
            // Always the first day of the budgeted month, so each month is planned independently.
            $table->date('month');
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->unique(['expense_type_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_expense_budgets');
    }
};
