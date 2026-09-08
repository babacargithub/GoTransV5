<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frees the `roles` attribute name on the User model for spatie/laravel-permission's
 * `roles()` relationship. The legacy column held a PHP-serialized array of Symfony
 * `ROLE_*` strings and is still read by the mobile-app /login response
 * (routes/api.php), so it is renamed rather than dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('roles', 'legacy_roles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('legacy_roles', 'roles');
        });
    }
};
