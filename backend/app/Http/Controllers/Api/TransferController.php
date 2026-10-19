<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\TransactionResource;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;

/** Mutasi antar kas (mis. Rekening Gereja → Kas Sekretariat). */
class TransferController extends Controller
{
    public function __construct(private TransferService $transfers) {}

    public function show(string $group): JsonResponse
    {
        return $this->respond($group);
    }

    public function store(TransferRequest $request): JsonResponse
    {
        $data = $request->validated();
        TransferService::ensureAccountsDiffer((int) $data['from_cash_account_id'], (int) $data['to_cash_account_id']);

        $legs = $this->transfers->create($data, $request->user()->id);

        return $this->respond($legs->first()->transfer_group, 201);
    }

    public function update(TransferRequest $request, string $group): JsonResponse
    {
        $this->transfers->update($group, $request->validated());

        return $this->respond($group);
    }

    public function destroy(string $group): JsonResponse
    {
        $this->transfers->delete($group);

        return response()->json(['message' => 'Mutasi antar kas dihapus.']);
    }

    private function respond(string $group, int $status = 200): JsonResponse
    {
        $legs = $this->transfers->legs($group)->load(['category', 'cashAccount']);

        return response()->json(['data' => [
            'transfer_group' => $group,
            'legs' => TransactionResource::collection($legs),
        ]], $status);
    }
}
