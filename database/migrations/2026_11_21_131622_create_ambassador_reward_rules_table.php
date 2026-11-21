<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_reward_rules', function (Blueprint $table): void {
            $table->id();

            // The earning type the rule produces: acquisition | existing_customer_booking
            $table->string('earning_type', 30);

            // FCFA amount snapshotted on every earning the rule creates.
            $table->unsignedInteger('amount');

            // Paused rules reward nothing, whatever their period.
            $table->boolean('is_enabled')->default(false);

            // Optional period during which the rule applies (both bounds inclusive, open when null).
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Null: applies to every ambassador. Set: applies to this ambassador only and takes
            // precedence over a general rule of the same type.
            $table->foreignId('ambassador_id')
                ->nullable()
                ->constrained('ambassadors')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->index(['earning_type', 'is_enabled']);
        });

        $this->seedGeneralAcquisitionRuleFromAppParams();
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_reward_rules');
    }

    /**
     * The acquisition prime used to live in app_params.data: carry its current value over so rewards keep
     * behaving exactly as before until a rule is changed.
     */
    private function seedGeneralAcquisitionRuleFromAppParams(): void
    {
        $appParamsData = json_decode((string) DB::table('app_params')->value('data'), true) ?? [];

        DB::table('ambassador_reward_rules')->insert([
            'earning_type' => 'acquisition',
            'amount' => (int) ($appParamsData['ambassador_acquisition_amount'] ?? 500),
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
