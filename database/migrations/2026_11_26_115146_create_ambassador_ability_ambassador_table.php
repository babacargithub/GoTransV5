<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_ability_ambassador', function (Blueprint $table): void {
            $table->foreignId('ambassador_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ambassador_ability_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['ambassador_id', 'ambassador_ability_id']);
        });

        // Every ambassador could sponsor before abilities existed: keep it that way until a manager revokes it.
        $sponsorAbilityId = DB::table('ambassador_abilities')->insertGetId([
            'code' => 'sponsor',
            'label' => 'Parrainer d\'autres ambassadeurs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ambassadors')->pluck('id')->chunk(500)->each(
            fn ($ambassadorIds) => DB::table('ambassador_ability_ambassador')->insert(
                $ambassadorIds->map(fn (int $ambassadorId): array => [
                    'ambassador_id' => $ambassadorId,
                    'ambassador_ability_id' => $sponsorAbilityId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
            ),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_ability_ambassador');
    }
};
