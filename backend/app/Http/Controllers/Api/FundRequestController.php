<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FundReportItemRequest;
use App\Http\Requests\FundRequestRequest;
use App\Http\Resources\FundRequestResource;
use App\Models\FundReportItem;
use App\Models\FundRequest;
use App\Services\FundRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Pengajuan dana operasional + transfer + LPJ (mekanisme kas kecil, mis. Sekretariat). */
class FundRequestController extends Controller
{
    public function __construct(private FundRequestService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'cash_account_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $requests = FundRequest::query()
            ->with(['cashAccount', 'items', 'transfers', 'report.items'])
            ->when($filters['cash_account_id'] ?? null, fn ($q, $v) => $q->where('cash_account_id', $v))
            ->when($filters['year'] ?? null, fn ($q, $v) => $q->where('period', 'like', $v.'-%'))
            ->orderByDesc('period')
            ->get();

        return FundRequestResource::collection($requests);
    }

    public function store(FundRequestRequest $request): FundRequestResource
    {
        $data = $request->validated();

        $fundRequest = DB::transaction(function () use ($data, $request) {
            $fundRequest = FundRequest::create([...collect($data)->except('items')->all(), 'created_by' => $request->user()->id]);
            $fundRequest->items()->createMany($data['items']);

            return $fundRequest;
        });

        return new FundRequestResource($this->service->load($fundRequest));
    }

    public function show(FundRequest $fundRequest): FundRequestResource
    {
        return new FundRequestResource($this->service->load($fundRequest));
    }

    public function update(FundRequestRequest $request, FundRequest $fundRequest): FundRequestResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $fundRequest) {
            $fundRequest->update(collect($data)->except('items')->all());
            $fundRequest->items()->delete();
            $fundRequest->items()->createMany($data['items']);
        });

        return new FundRequestResource($this->service->load($fundRequest->fresh()));
    }

    public function destroy(FundRequest $fundRequest): JsonResponse
    {
        $this->service->delete($fundRequest->load('report.items.attachments'));

        return response()->json(['message' => 'Pengajuan beserta transfer & LPJ-nya dihapus.']);
    }

    /** Bendahara mencatat transfer dana untuk pengajuan ini (mutasi antar kas). */
    public function storeTransfer(Request $request, FundRequest $fundRequest): FundRequestResource
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_cash_account_id' => ['required', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->service->addTransfer($fundRequest->load('cashAccount'), $data, $request->user()->id);

        return new FundRequestResource($this->service->load($fundRequest));
    }

    /** Catatan LPJ & tandai lengkap/belum. */
    public function updateReport(Request $request, FundRequest $fundRequest): FundRequestResource
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'submitted' => ['required', 'boolean'],
        ]);

        $report = $this->service->report($fundRequest, $request->user()->id);
        $report->update([
            'notes' => $data['notes'] ?? null,
            'submitted_at' => $data['submitted'] ? ($report->submitted_at ?? now()) : null,
        ]);

        return new FundRequestResource($this->service->load($fundRequest));
    }

    public function storeItem(FundReportItemRequest $request, FundRequest $fundRequest): FundRequestResource
    {
        $this->service->saveItem($fundRequest, $request->validated(), $request->user()->id);

        return new FundRequestResource($this->service->load($fundRequest));
    }

    public function updateItem(FundReportItemRequest $request, FundReportItem $item): FundRequestResource
    {
        $fundRequest = $item->report->fundRequest;
        $this->service->saveItem($fundRequest, $request->validated(), $request->user()->id, $item);

        return new FundRequestResource($this->service->load($fundRequest));
    }

    public function destroyItem(FundReportItem $item): FundRequestResource
    {
        $fundRequest = $item->report->fundRequest;
        $this->service->deleteItem($item);

        return new FundRequestResource($this->service->load($fundRequest));
    }
}
