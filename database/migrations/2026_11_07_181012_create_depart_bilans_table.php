<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depart_bilans', function (Blueprint $table): void {
            $table->id();

            // departs.id is a legacy signed int(11), so foreignId() (bigint unsigned) cannot reference it.
            $table->integer('depart_id')->unique();
            $table->foreign('depart_id')->references('id')->on('departs')->cascadeOnDelete();

            $table->text('notes')->nullable();

            // users.id is a legacy int(11) too: plain indexed columns, no DB-level foreign key.
            $table->integer('created_by')->nullable()->index();
            $table->integer('updated_by')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depart_bilans');
    }
};
