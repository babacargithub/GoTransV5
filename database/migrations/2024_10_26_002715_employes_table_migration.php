<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //

        Schema::rename('employe','employes');
        Schema::rename('employee_category','employe_categories');

        Schema::table('employes', function (Blueprint $table) {
            $table->renameColumn("category_id","employe_category_id");
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::rename('employes','employe');
        Schema::rename('employe_categories','employee_category');

    }
};
