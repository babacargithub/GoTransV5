<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_contract_signatures', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ambassador_id')->constrained()->cascadeOnDelete();

            // The contract version that was signed. Documents are never deleted, so the link always resolves.
            $table->foreignId('contract_document_id')->constrained('ambassador_program_documents')->restrictOnDelete();

            // Evidence: the exact text shown to the ambassador, and its fingerprint.
            $table->longText('content_snapshot');
            $table->char('content_sha256', 64);

            // What the ambassador typed as their signature, and the phone number that confirmed it by SMS code.
            $table->string('typed_full_name');
            $table->unsignedBigInteger('confirmed_phone_number');

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('signed_at');

            $table->timestamps();

            // One signature per contract version.
            $table->unique(['ambassador_id', 'contract_document_id'], 'ambassador_contract_signatures_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_contract_signatures');
    }
};
