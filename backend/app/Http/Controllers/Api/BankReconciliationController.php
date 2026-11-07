<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankReconciliationRequest;
use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Rekonsiliasi per kas per akhir bulan: saldo aplikasi vs rekening koran / hitung fisik. */
class BankReconciliationController extends Controller
{
    public function __construct(private FinanceReportService $report) {}

    public function index(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->subMonthNoOverflow()->format('Y-m');
        $after = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay()->addMonthNoOverflow();

        $records = BankReconciliation::with('reconciler:id,name')->where('month', $month)->get()->keyBy('cash_account_id');
        $accounts = CashAccount::orderBy('type')->orderBy('code')->get()
            ->filter(fn (CashAccount $a) => $a->is_active || $records->has($a->id))
            ->map(function (CashAccount $a) use ($records, $after) {
                $record = $records[$a->id] ?? null;
                $app = $this->report->balance($a->id, $after);
                $statement = $record ? (float) $record->statement_balance : null;

                return [
                    'cash_account_id' => $a->id,
                    'name' => $a->name,
                    'type' => $a->type->value,
                    'type_label' => $a->type->label(),
                    'account_number' => $a->account_number,
                    'app_balance' => $app,
                    'id' => $record?->id,
                    'statement_balance' => $statement,
                    'difference' => $statement === null ? null : round($statement - $app, 2),
                    'notes' => $record?->notes,
                    'reconciled_by' => $record?->reconciler?->name,
                    'reconciled_at' => $record?->updated_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json(['month' => $month, 'accounts' => $accounts]);
    }

    public function store(BankReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();
        BankReconciliation::updateOrCreate(
            ['cash_account_id' => $data['cash_account_id'], 'month' => $data['month']],
            ['statement_balance' => $data['statement_balance'], 'notes' => $data['notes'] ?? null, 'reconciled_by' => $request->user()->id],
        );

        return response()->json(['message' => 'Rekonsiliasi disimpan.']);
    }

    public function destroy(BankReconciliation $bankReconciliation): JsonResponse
    {
        $bankReconciliation->delete();

        return response()->json(['message' => 'Rekonsiliasi dihapus.']);
    }
}
