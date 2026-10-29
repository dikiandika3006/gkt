<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 20);
            $table->string('module', 40);
            $table->string('level', 10); // none | view | manage
            $table->timestamps();

            $table->unique(['role', 'module']);
        });

        // Hak awal: bendahara & viewer sama seperti sebelumnya; sekretariat hanya Kas Sekretariat + laporannya.
        // Nilai ditulis langsung (bukan dari enum) agar migrasi tetap sama walau enum berubah.
        $modules = ['dashboard', 'transactions', 'offerings', 'recurring', 'budgets', 'fund_requests', 'reports', 'report_secretariat', 'categories', 'cash_accounts'];
        $viewOnly = ['dashboard', 'reports', 'report_secretariat'];
        $sekretariat = ['fund_requests' => 'manage', 'report_secretariat' => 'view'];
        $now = now();

        $rows = [];
        foreach ($modules as $module) {
            $rows[] = ['role' => 'bendahara', 'module' => $module, 'level' => in_array($module, $viewOnly, true) ? 'view' : 'manage'];
            $rows[] = ['role' => 'viewer', 'module' => $module, 'level' => 'view'];
            $rows[] = ['role' => 'sekretariat', 'module' => $module, 'level' => $sekretariat[$module] ?? 'none'];
        }

        DB::table('role_permissions')->insert(array_map(fn ($r) => [...$r, 'created_at' => $now, 'updated_at' => $now], $rows));
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
