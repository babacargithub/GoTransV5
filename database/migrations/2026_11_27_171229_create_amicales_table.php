<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amicales', function (Blueprint $table): void {
            $table->id();

            $table->string('name');

            // Subdomain of the public website the amicale's members book on: "{slug}.{public website domain}".
            $table->string('slug', 63)->unique();

            // Shown on the amicale's site; the default partnership sentence is used when empty.
            $table->string('welcome_message', 500)->nullable();

            // FCFA the amicale earns per ticket booked on its site, as agreed in their contract.
            $table->unsignedInteger('commission_per_ticket');

            $table->boolean('is_active')->default(true);

            // users.id is a legacy int(11), so no DB foreign key (same convention as ambassadors.user_account_id).
            // Unique: one login account per amicale.
            $table->integer('user_account_id')->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amicales');
    }
};
