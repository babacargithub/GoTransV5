<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLE_NAME = 'ROLE_AMICALE';

    /**
     * Seed the role carried by every amicale's user account.
     */
    public function up(): void
    {
        DB::table('roles')->insertOrIgnore([
            'name' => self::ROLE_NAME,
            'label' => 'Amicale',
            'guard_name' => config('auth.defaults.guard', 'web'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')->where('name', self::ROLE_NAME)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
