<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('depart_bilans', 'depart_cost_reports');
        Schema::rename('depart_bilan_bus_rentals', 'depart_cost_report_bus_rentals');

        Schema::table('depart_cost_report_bus_rentals', function (Blueprint $table): void {
            $table->renameColumn('depart_bilan_id', 'depart_cost_report_id');
        });

        DB::table('permissions')->where('name', 'manage-depart-bilan')->update(['name' => 'manage-depart-cost-report']);
        DB::table('account_transactions')->where('reference_type', 'DEPART_BILAN')->update(['reference_type' => 'DEPART_COST_REPORT']);
    }

    public function down(): void
    {
        DB::table('account_transactions')->where('reference_type', 'DEPART_COST_REPORT')->update(['reference_type' => 'DEPART_BILAN']);
        DB::table('permissions')->where('name', 'manage-depart-cost-report')->update(['name' => 'manage-depart-bilan']);

        Schema::table('depart_cost_report_bus_rentals', function (Blueprint $table): void {
            $table->renameColumn('depart_cost_report_id', 'depart_bilan_id');
        });

        Schema::rename('depart_cost_report_bus_rentals', 'depart_bilan_bus_rentals');
        Schema::rename('depart_cost_reports', 'depart_bilans');
    }
};
