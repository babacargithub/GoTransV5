<?php

use App\Models\Customer;
use App\Models\Depart;
use App\Models\Bus;
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
        Schema::create('waiting_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger("customer_id");
            $table->unsignedInteger("depart_id");
            $table->unsignedInteger("bus_id");


            $table->json('data')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waiting_customers');
    }
};
