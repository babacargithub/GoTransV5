<?php

use App\Enums\CaisseCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // Stable programmatic identifier for the built-in system caisses
            // (payment-method routing looks these up by code, never by name).
            // Null for ad-hoc caisses created later through the UI.
            $table->string('code')->nullable()->unique();

            // Cached balance. Never set directly — always via a CaisseTransaction.
            $table->integer('balance')->default(0);

            $table->boolean('closed')->default(false);

            // When set to today's date the caisse is locked for manual operations
            // (entrée / sortie / transfer) until the date rolls over.
            $table->date('locked_until')->nullable();

            $table->timestamps();
        });

        $now = now();

        DB::table('caisses')->insert([
            ['name' => 'Wave', 'code' => CaisseCode::Wave->value, 'balance' => 0, 'closed' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'OM', 'code' => CaisseCode::OrangeMoney->value, 'balance' => 0, 'closed' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Ticket Cash', 'code' => CaisseCode::TicketCash->value, 'balance' => 0, 'closed' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Caisse Principale', 'code' => CaisseCode::Principale->value, 'balance' => 0, 'closed' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('caisses');
    }
};
