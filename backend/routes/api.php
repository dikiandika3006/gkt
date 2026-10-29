<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankImportController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CounterpartyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FundRequestController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\OfferingReportController;
use App\Http\Controllers\Api\OfferingSettingController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RecurringExpenseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

/*
 * Hak akses per modul diatur di menu Hak Akses (role × modul): permission:<modul>,<view|manage>.
 * Admin selalu penuh. Rute statis (export, summary, template, next-code) didaftarkan sebelum rute {id}.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Data pilihan untuk dropdown (nama kategori & kas) — dibutuhkan semua modul, termasuk Kas Sekretariat.
    Route::apiResource('categories', CategoryController::class)->only(['index']);
    Route::apiResource('cash-accounts', CashAccountController::class)->only(['index']);
    // Hak per jenis lampiran dicek di controller (mengikuti modul induknya).
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download']);
    Route::post('/attachments', [AttachmentController::class, 'store']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // Dashboard keuangan gereja + Insight + Tanya Keuangan.
    Route::middleware('permission:dashboard,view')->group(function () {
        Route::get('/dashboard', DashboardController::class);
        Route::get('/insights', InsightController::class);
        Route::get('/assistant/starters', [AssistantController::class, 'starters']);
        Route::post('/assistant/ask', [AssistantController::class, 'ask'])->middleware('throttle:30,1');
    });

    // Mutasi (transaksi kas, import CSV bank, mutasi antar kas).
    Route::middleware('permission:transactions,view')->group(function () {
        Route::get('/transactions/export', [TransactionController::class, 'export']);
        Route::get('/counterparties', CounterpartyController::class);
        Route::get('/transfers/{group}', [TransferController::class, 'show'])->whereUuid('group');
        Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);
    });
    Route::middleware('permission:transactions,manage')->group(function () {
        Route::post('/transactions/import/preview', [BankImportController::class, 'preview']);
        Route::post('/transactions/import', [BankImportController::class, 'store']);
        Route::apiResource('transactions', TransactionController::class)->except(['index', 'show']);
        Route::post('/transfers', [TransferController::class, 'store']);
        Route::put('/transfers/{group}', [TransferController::class, 'update'])->whereUuid('group');
        Route::delete('/transfers/{group}', [TransferController::class, 'destroy'])->whereUuid('group');
        // Transfer dana ke pengajuan = mutasi antar kas oleh bendahara.
        Route::post('/fund-requests/{fund_request}/transfers', [FundRequestController::class, 'storeTransfer']);
    });

    // Rekap persembahan mingguan (warta).
    Route::middleware('permission:offerings,view')->group(function () {
        Route::get('/offering-reports/template', [OfferingReportController::class, 'template']);
        Route::get('/offering-settings', [OfferingSettingController::class, 'show']);
        Route::get('/offering-reports/{offering_report}/candidates', [OfferingReportController::class, 'candidates']);
        Route::apiResource('offering-reports', OfferingReportController::class)->only(['index', 'show']);
    });
    Route::middleware('permission:offerings,manage')->group(function () {
        Route::apiResource('offering-reports', OfferingReportController::class)->only(['store', 'update', 'destroy']);
        Route::put('/offering-reports/{offering_report}/transactions', [OfferingReportController::class, 'syncTransactions']);
        Route::put('/offering-settings', [OfferingSettingController::class, 'update']);
    });

    // Biaya rutin.
    Route::middleware('permission:recurring,view')->group(function () {
        Route::get('/recurring-expenses/status', [RecurringExpenseController::class, 'status']);
        Route::apiResource('recurring-expenses', RecurringExpenseController::class)->only(['index', 'show']);
    });
    Route::apiResource('recurring-expenses', RecurringExpenseController::class)->except(['index', 'show'])
        ->middleware('permission:recurring,manage');

    // Anggaran.
    Route::get('/budgets', [BudgetController::class, 'index'])->middleware('permission:budgets,view');
    Route::middleware('permission:budgets,manage')->group(function () {
        Route::post('/budgets/copy', [BudgetController::class, 'copy']);
        Route::apiResource('budgets', BudgetController::class)->only(['store', 'update', 'destroy']);
    });

    // Kas Sekretariat: pengajuan dana & LPJ (tanpa approval).
    Route::middleware('permission:fund_requests,view')->group(function () {
        Route::get('/fund-requests/summary', [FundRequestController::class, 'summary']);
        Route::apiResource('fund-requests', FundRequestController::class)->only(['index', 'show']);
    });
    Route::middleware('permission:fund_requests,manage')->group(function () {
        Route::apiResource('fund-requests', FundRequestController::class)->except(['index', 'show']);
        Route::put('/fund-requests/{fund_request}/report', [FundRequestController::class, 'updateReport']);
        Route::post('/fund-requests/{fund_request}/report/items', [FundRequestController::class, 'storeItem']);
        Route::put('/fund-report-items/{item}', [FundRequestController::class, 'updateItem']);
        Route::delete('/fund-report-items/{item}', [FundRequestController::class, 'destroyItem']);
    });

    // Laporan. Export dicek per jenis laporan di controller.
    Route::middleware('permission:reports,view')->group(function () {
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow']);
        Route::get('/reports/monthly', [ReportController::class, 'monthly']);
        Route::get('/reports/categories', [ReportController::class, 'categories']);
    });
    Route::get('/reports/secretariat', [ReportController::class, 'secretariat'])->middleware('permission:report_secretariat,view');
    Route::get('/reports/{name}/export', [ReportController::class, 'export'])
        ->whereIn('name', ['cash-flow', 'monthly', 'categories', 'secretariat'])
        ->middleware('permission:reports|report_secretariat,view');

    // Master data.
    Route::get('/categories/next-code', [CategoryController::class, 'nextCode'])->middleware('permission:categories,manage');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->middleware('permission:categories,view');
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show'])->middleware('permission:categories,manage');
    Route::get('/cash-accounts/{cash_account}', [CashAccountController::class, 'show'])->middleware('permission:cash_accounts,view');
    Route::apiResource('cash-accounts', CashAccountController::class)->except(['index', 'show'])->middleware('permission:cash_accounts,manage');

    // Admin saja.
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::put('/permissions', [PermissionController::class, 'update']);
    });
});
