<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pocket = sub-rekening dana khusus (mis. Pembangunan, Natal) di dalam satu rekening.
     * Rekening ber-pocket wajib mengisi pocket di setiap transaksinya.
     */
    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->boolean('has_pockets')->default(false)->after('opening_balance');
        });

        Schema::create('cash_account_pockets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['cash_account_id', 'name']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('pocket_id')->nullable()->after('cash_account_id')
                ->constrained('cash_account_pockets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pocket_id');
        });
        Schema::dropIfExists('cash_account_pockets');
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropColumn('has_pockets');
        });
    }
};
