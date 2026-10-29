<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pos persembahan khusus (kantong minggu tertentu) beserta rekening tujuannya.
        Schema::create('offering_funds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('cash_account_id')->nullable()->constrained()->nullOnDelete(); // rekening di sistem, mis. Rekening Utama
            $table->string('bank_name', 50)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('account_holder', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Jadwal: hari Minggu ke-N dalam bulan → pos persembahan khusus.
        Schema::create('offering_week_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('week')->unique(); // 1–5
            $table->foreignId('offering_fund_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offering_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->json('value');
            $table->timestamps();
        });

        Schema::table('offering_reports', function (Blueprint $table) {
            $table->unsignedTinyInteger('week_of_month')->nullable()->after('service_date');
        });

        Schema::table('offering_report_lines', function (Blueprint $table) {
            $table->foreignId('offering_fund_id')->nullable()->after('section')->constrained()->nullOnDelete();
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::table('offering_report_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('offering_fund_id'));
        Schema::table('offering_reports', fn (Blueprint $table) => $table->dropColumn('week_of_month'));
        Schema::dropIfExists('offering_settings');
        Schema::dropIfExists('offering_week_schedules');
        Schema::dropIfExists('offering_funds');
    }

    /** Isi awal sesuai praktik gereja: M1 Diakonia, M2 PI Misi, M3 Kendaraan, M4 Ibadah, M5 Diakonia. */
    private function seedDefaults(): void
    {
        $now = now();
        $mainAccountId = DB::table('cash_accounts')->whereIn('code', ['UTAMA', 'BANK-01'])->orderByRaw("CASE code WHEN 'UTAMA' THEN 0 ELSE 1 END")->value('id')
            ?? DB::table('cash_accounts')->where('type', 'bank')->orderBy('id')->value('id');

        $ids = [];
        foreach (['Diakonia', 'PI Misi', 'Kendaraan', 'Ibadah'] as $i => $name) {
            $ids[$name] = DB::table('offering_funds')->insertGetId([
                'name' => $name,
                // Rekening pos lain diisi bendahara di Master Pos Persembahan.
                'cash_account_id' => $name === 'Ibadah' ? $mainAccountId : null,
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([1 => 'Diakonia', 2 => 'PI Misi', 3 => 'Kendaraan', 4 => 'Ibadah', 5 => 'Diakonia'] as $week => $name) {
            DB::table('offering_week_schedules')->insert(['week' => $week, 'offering_fund_id' => $ids[$name], 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('offering_settings')->insert(['key' => 'services', 'value' => json_encode(['KU I', 'KU II']), 'created_at' => $now, 'updated_at' => $now]);
    }
};
