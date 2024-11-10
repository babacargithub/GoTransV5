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
        Schema::rename('caravane_client','customers');


        Schema::rename('crm_categorie_client','customer_categories');

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn("tel","phone_number");

            $table->dateTime("updated_at")->nullable();
            $table->renameColumn("deleated_at","deleted_at");

        });
//        $table->renameColumn('categorie_client', 'customer_category_id');
//        $table->foreign('customer_category_id')->references('id')->on('customer_categories')->onDelete('set null');
//
//    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::rename('customers','caravane_client');
        Schema::table('caravane_client', function (Blueprint $table) {
            $table->renameColumn("phone_number","tel");

            $table->renameColumn('customer_category_id', 'categorie_client');

        });
    }
};
