<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('fund_request_id')->nullable()->after('transfer_group')->constrained()->nullOnDelete();
            $table->foreignId('fund_report_item_id')->nullable()->unique()->after('fund_request_id')->constrained()->nullOnDelete();
            $table->foreignId('recurring_expense_id')->nullable()->after('fund_report_item_id')->constrained()->nullOnDelete();
            $table->string('recurring_period', 7)->nullable()->after('recurring_expense_id'); // YYYY-MM

            $table->index(['recurring_expense_id', 'recurring_period']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['recurring_expense_id', 'recurring_period']);
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropConstrainedForeignId('fund_report_item_id');
            $table->dropConstrainedForeignId('fund_request_id');
            $table->dropColumn('recurring_period');
        });
    }
};
