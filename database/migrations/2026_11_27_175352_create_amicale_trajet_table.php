<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amicale_trajet', function (Blueprint $table): void {
            $table->foreignId('amicale_id')->constrained('amicales')->cascadeOnDelete();

            // trajets.id is a legacy int(11), so no DB foreign key (see the convention in create_ambassadors_table).
            $table->integer('trajet_id')->index();

            $table->primary(['amicale_id', 'trajet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amicale_trajet');
    }
};
