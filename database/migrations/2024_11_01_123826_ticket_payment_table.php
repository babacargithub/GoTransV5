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
        Schema::drop("achat_ticket");
        Schema::rename("payer","ticket_payments");
        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->integer("montant")->default(0);
            $table->dateTime("updated_at")->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::create("achat_ticket", function (Blueprint $table) {
            $table->id();
            $table->integer("ticket_id");
            $table->integer("user_id");
            $table->integer("quantite");
            $table->integer("montant");
            $table->timestamps();
        });
        Schema::rename("ticket_payments","payer");
    }
};
