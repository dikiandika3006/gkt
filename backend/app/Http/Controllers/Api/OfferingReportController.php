<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OfferingReportRequest;
use App\Http\Resources\OfferingReportResource;
use App\Models\OfferingReport;
use App\Services\OfferingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Rekap persembahan mingguan (warta) + crosscheck dengan mutasi masuk. */
class OfferingReportController extends Controller
{
    public function __construct(private OfferingReportService $service) {}

    /** Riwayat rekap per bulan + ringkasan bulan. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $start = Carbon::create($data['year'] ?? now()->year, $data['month'] ?? now()->month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $reports = OfferingReport::with(['lines', 'transactions'])
            ->whereDate('service_date', '>=', $start)
            ->whereDate('service_date', '<=', $end)
            ->orderByDesc('service_date')
            ->get();

        $defaultId = $this->service->defaultAccount()?->id;
        $summaries = $reports->map(fn (OfferingReport $r) => $this->service->summary($r, $defaultId))->all();

        return response()->json([
            'period' => ['year' => $start->year, 'month' => $start->month],
            'month_summary' => $this->service->monthSummary($reports, $summaries),
            'data' => $reports->values()->map(fn (OfferingReport $r, int $i) => [
                'id' => $r->id,
                'service_date' => $r->service_date->toDateString(),
                'period_start' => $r->period_start->toDateString(),
                'period_end' => $r->period_end->toDateString(),
                'summary' => $summaries[$i],
            ]),
        ]);
    }

    /** Baris tetap Persembahan Ibadah + pos minggu itu untuk tanggal ibadah tertentu. */
    public function template(Request $request): JsonResponse
    {
        $data = $request->validate(['service_date' => ['nullable', 'date']]);

        return response()->json($this->service->template($data['service_date'] ?? null));
    }

    public function store(OfferingReportRequest $request): OfferingReportResource
    {
        $report = $this->service->save($request->validated(), $request->user()->id);

        return new OfferingReportResource($this->service->load($report));
    }

    public function show(OfferingReport $offeringReport): OfferingReportResource
    {
        return new OfferingReportResource($this->service->load($offeringReport));
    }

    public function update(OfferingReportRequest $request, OfferingReport $offeringReport): OfferingReportResource
    {
        $this->service->save($request->validated(), $request->user()->id, $offeringReport);

        return new OfferingReportResource($this->service->load($offeringReport->fresh()));
    }

    public function destroy(OfferingReport $offeringReport): JsonResponse
    {
        $offeringReport->delete();

        return response()->json(['message' => 'Rekap persembahan dihapus.']);
    }

    /** Kandidat mutasi masuk untuk dicocokkan (default Senin–Sabtu setelah tanggal ibadah). */
    public function candidates(Request $request, OfferingReport $offeringReport): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
        ]);

        [$defaultStart, $defaultEnd] = $this->service->defaultWindow($offeringReport);
        $start = isset($data['start_date']) ? Carbon::parse($data['start_date']) : $defaultStart;
        $end = isset($data['end_date']) ? Carbon::parse($data['end_date']) : $defaultEnd;
        $accountId = $data['cash_account_id'] ?? $this->service->defaultAccount()?->id;
        abort_unless($accountId, 422, 'Rekening Gereja belum ada. Tambahkan di menu Kas & Bank.');

        return response()->json([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'cash_account_id' => (int) $accountId,
            'data' => $this->service->candidates($offeringReport, $start, $end, (int) $accountId),
        ]);
    }

    public function syncTransactions(Request $request, OfferingReport $offeringReport): OfferingReportResource
    {
        $data = $request->validate([
            'transaction_ids' => ['present', 'array', 'max:500'],
            'transaction_ids.*' => ['integer', 'distinct'],
        ]);

        $this->service->syncTransactions($offeringReport, $data['transaction_ids']);

        return new OfferingReportResource($this->service->load($offeringReport->fresh()));
    }
}
