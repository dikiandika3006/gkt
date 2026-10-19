<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccountRequest;
use App\Http\Resources\CashAccountResource;
use App\Models\CashAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = CashAccount::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        return CashAccountResource::collection($accounts);
    }

    public function store(CashAccountRequest $request): CashAccountResource
    {
        return new CashAccountResource(CashAccount::create($request->validated()));
    }

    public function show(CashAccount $cashAccount): CashAccountResource
    {
        return new CashAccountResource($cashAccount);
    }

    public function update(CashAccountRequest $request, CashAccount $cashAccount): CashAccountResource
    {
        $cashAccount->update($request->validated());

        return new CashAccountResource($cashAccount);
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
