<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccountRequest;
use App\Http\Resources\CashAccountResource;
use App\Models\CashAccount;
use App\Models\CashAccountPocket;
use App\Services\CashAccountService;
use App\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashAccountController extends Controller
{
    public function __construct(private CashAccountService $accounts) {}

    public function index(Request $request, FinanceReportService $report): AnonymousResourceCollection
    {
        $accounts = CashAccount::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->with('pockets')
            ->orderBy('code')
            ->get();

        $mutations = $report->mutationsPerAccount();
        $pocketMutations = $report->mutationsPerPocket();
        $accounts->each(function (CashAccount $account) use ($mutations, $pocketMutations) {
            $account->setAttribute(
                'current_balance',
                round((float) $account->opening_balance + (float) ($mutations[$account->id] ?? 0), 2),
            );
            $account->pockets->each(fn (CashAccountPocket $pocket) => $pocket->setAttribute(
                'current_balance',
                round((float) $pocket->opening_balance + (float) ($pocketMutations[$pocket->id] ?? 0), 2),
            ));
        });

        return CashAccountResource::collection($accounts);
    }

    public function store(CashAccountRequest $request): CashAccountResource
    {
        return new CashAccountResource($this->accounts->save($request->validated())->load('pockets'));
    }

    public function show(CashAccount $cashAccount): CashAccountResource
    {
        return new CashAccountResource($cashAccount->load('pockets'));
    }

    public function update(CashAccountRequest $request, CashAccount $cashAccount): CashAccountResource
    {
        return new CashAccountResource($this->accounts->save($request->validated(), $cashAccount)->load('pockets'));
    }

    public function destroy(CashAccount $cashAccount): JsonResponse
    {
        if ($cashAccount->transactions()->withTrashed()->exists()) {
            abort(422, 'Kas/rekening sudah dipakai transaksi. Nonaktifkan saja.');
        }

        $cashAccount->delete();

        return response()->json(['message' => 'Kas/rekening dihapus.']);
    }
}
