<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassador_program_documents', function (Blueprint $table): void {
            // Every edit of the contract creates a new version, so the text an ambassador signed is never rewritten.
            // The current document of a type is its highest version.
            $table->unsignedInteger('version')->default(1)->after('type');

            $table->dropUnique(['type']);
            $table->unique(['type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_program_documents', function (Blueprint $table): void {
            $table->dropUnique(['type', 'version']);
            $table->unique('type');
            $table->dropColumn('version');
        });
    }
};
