<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankImportController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CounterpartyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FundRequestController;
use App\Http\Controllers\Api\RecurringExpenseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Semua role boleh membaca.
    Route::get('/dashboard', DashboardController::class);
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow']);
    Route::get('/reports/monthly', [ReportController::class, 'monthly']);
    Route::get('/reports/categories', [ReportController::class, 'categories']);
    Route::get('/reports/secretariat', [ReportController::class, 'secretariat']);
    Route::get('/reports/{name}/export', [ReportController::class, 'export'])->whereIn('name', ['cash-flow', 'monthly', 'categories', 'secretariat']);
    Route::get('/transactions/export', [TransactionController::class, 'export']);
    Route::get('/counterparties', CounterpartyController::class);
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download']);
    Route::get('/recurring-expenses/status', [RecurringExpenseController::class, 'status']);
    Route::get('/transfers/{group}', [TransferController::class, 'show'])->whereUuid('group');
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    Route::apiResource('cash-accounts', CashAccountController::class)->only(['index', 'show']);
    Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);
    Route::apiResource('recurring-expenses', RecurringExpenseController::class)->only(['index', 'show']);
    Route::apiResource('fund-requests', FundRequestController::class)->only(['index', 'show']);

    // Tulis data: admin & bendahara.
    Route::middleware('role:admin,bendahara')->group(function () {
        Route::post('/transactions/import/preview', [BankImportController::class, 'preview']);
        Route::post('/transactions/import', [BankImportController::class, 'store']);
        Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
        Route::apiResource('cash-accounts', CashAccountController::class)->except(['index', 'show']);
        Route::apiResource('transactions', TransactionController::class)->except(['index', 'show']);
        Route::apiResource('recurring-expenses', RecurringExpenseController::class)->except(['index', 'show']);
        Route::post('/transfers', [TransferController::class, 'store']);
        Route::put('/transfers/{group}', [TransferController::class, 'update'])->whereUuid('group');
        Route::delete('/transfers/{group}', [TransferController::class, 'destroy'])->whereUuid('group');
        Route::post('/fund-requests/{fund_request}/transfers', [FundRequestController::class, 'storeTransfer']);
    });

    // Pengajuan dana & LPJ: admin, bendahara, sekretariat (tanpa approval).
    Route::middleware('role:admin,bendahara,sekretariat')->group(function () {
        Route::apiResource('fund-requests', FundRequestController::class)->except(['index', 'show']);
        Route::put('/fund-requests/{fund_request}/report', [FundRequestController::class, 'updateReport']);
        Route::post('/fund-requests/{fund_request}/report/items', [FundRequestController::class, 'storeItem']);
        Route::put('/fund-report-items/{item}', [FundRequestController::class, 'updateItem']);
        Route::delete('/fund-report-items/{item}', [FundRequestController::class, 'destroyItem']);
        // Hak per jenis lampiran dicek lagi di controller.
        Route::post('/attachments', [AttachmentController::class, 'store']);
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);
    });

    // Admin saja.
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    });
});
