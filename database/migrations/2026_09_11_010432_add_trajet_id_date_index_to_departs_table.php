<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public caravane page (and the mobile départ list) filters departs by `trajet_id` and
 * `date >= now()` then orders by `date`. The existing single-column `trajet_id` index leaves
 * MySQL to filter the date and filesort; a composite covers both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departs', function (Blueprint $table) {
            $table->index(['trajet_id', 'date'], 'departs_trajet_id_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('departs', function (Blueprint $table) {
            $table->dropIndex('departs_trajet_id_date_index');
        });
    }
};
