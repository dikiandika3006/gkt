<?php

namespace App\Http\Controllers\Api;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Services\ExcelExportService;
use App\Services\FinanceReportService;
use App\Services\FundRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private FinanceReportService $report, private FundRequestService $fundRequests) {}

    /** Rekap pemasukan & pengeluaran per kategori + saldo awal/akhir. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->summaryData(...$this->period($request)));
    }

    /** Buku kas harian dengan saldo berjalan. */
    public function cashFlow(Request $request): JsonResponse
    {
        [$start, $end, $cashAccountId] = $this->period($request);

        return response()->json([
            'period' => $this->periodArray($start, $end),
            ...$this->report->ledger($start, $end, $cashAccountId),
        ]);
    }

    /** Rekap bulanan (ringkasan per bulan + matriks kelompok/kategori × bulan). */
    public function monthly(Request $request): JsonResponse
    {
        [$start, $end, $cashAccountId] = $this->monthRange($request);

        return response()->json([
            'period' => $this->periodArray($start, $end),
            ...$this->report->monthlyMatrix($start, $end, $cashAccountId),
        ]);
    }

    /** Analisa pengeluaran operasional per kategori (peringkat). */
    public function categories(Request $request): JsonResponse
    {
        [$start, $end] = $this->period($request);

        return response()->json([
            'period' => $this->periodArray($start, $end),
            'rows' => $this->report->categoryRanking($start, $end),
        ]);
    }

    /** Laporan detail pemakaian dana Sekretariat (atau kas kecil lain). */
    public function secretariat(Request $request): JsonResponse
    {
        [$cashAccountId, $startPeriod, $endPeriod] = $this->secretariatParams($request);

        return response()->json($this->fundRequests->periodReport($cashAccountId, $startPeriod, $endPeriod));
    }

    public function export(Request $request, ExcelExportService $excel, string $name): StreamedResponse
    {
        $module = $name === 'secretariat' ? Module::ReportSecretariat : Module::Reports;
        abort_unless($request->user()->hasPermission($module), 403, 'Anda tidak memiliki akses untuk aksi ini.');

        return match ($name) {
            'cash-flow' => $this->exportCashFlow($request, $excel),
            'monthly' => $this->exportMonthly($request, $excel),
            'categories' => $this->exportCategories($request, $excel),
            'secretariat' => $this->exportSecretariat($request, $excel),
            default => abort(404, 'Laporan tidak dikenal.'),
        };
    }

    private function exportCashFlow(Request $request, ExcelExportService $excel): StreamedResponse
    {
        [$start, $end, $cashAccountId] = $this->period($request);
        $ledger = $this->report->ledger($start, $end, $cashAccountId);

        $rows = [['', '', 'Saldo awal', '', '', '', null, null, $ledger['opening_balance']]];
        foreach ($ledger['rows'] as $r) {
            $rows[] = [$r['date'], $r['number'], $r['description'], (string) $r['counterparty'], (string) $r['category'], (string) $r['cash_account'], $r['income'], $r['expense'], $r['balance']];
        }
        $rows[] = ['', '', 'Saldo akhir', '', '', '', null, null, $ledger['closing_balance']];

        return $excel->download("buku-kas-{$start->format('Ymd')}-{$end->format('Ymd')}.xlsx", [[
            'title' => 'Buku Kas',
            'heading' => ['Buku Kas', $this->periodLabel($start, $end, $cashAccountId)],
            'headers' => ['Tanggal', 'Nomor', 'Keterangan', 'Nama', 'Kategori', 'Kas', 'Masuk', 'Keluar', 'Saldo'],
            'rows' => $rows,
            'money_from' => 6,
        ]]);
    }

    private function exportMonthly(Request $request, ExcelExportService $excel): StreamedResponse
    {
        [$start, $end, $cashAccountId] = $this->monthRange($request);
        $data = $this->report->monthlyMatrix($start, $end, $cashAccountId);
        $months = collect($data['months'])->pluck('month')->all();

        $summaryRows = collect($data['months'])->map(fn ($m) => [
            $m['month'], $m['opening_balance'], $m['income'], $m['expense'], $m['net'],
            $m['non_operational_in'] - $m['non_operational_out'], $m['transfer_in'] - $m['transfer_out'], $m['closing_balance'],
        ])->all();

        $matrixRows = [];
        foreach (['income' => 'PEMASUKAN', 'expense' => 'PENGELUARAN'] as $key => $label) {
            $matrixRows[] = [$label, ''];
            foreach ($data[$key] as $group) {
                $matrixRows[] = [$group['group'], '', ...array_values($group['months']), $group['total']];
                foreach ($group['categories'] as $cat) {
                    $matrixRows[] = ['', $cat['name'], ...array_values($cat['months']), $cat['total']];
                }
            }
            $matrixRows[] = ['', ''];
        }

        $label = $this->periodLabel($start, $end, $cashAccountId);

        return $excel->download("rekap-bulanan-{$start->format('Ym')}-{$end->format('Ym')}.xlsx", [
            [
                'title' => 'Ringkasan',
                'heading' => ['Rekap Bulanan', $label],
                'headers' => ['Bulan', 'Saldo Awal', 'Pemasukan Operasional', 'Pengeluaran Operasional', 'Surplus/(Defisit)', 'Titipan & Pinjaman (bersih)', 'Mutasi Antar Kas (bersih)', 'Saldo Akhir'],
                'rows' => $summaryRows,
                'money_from' => 1,
            ],
            [
                'title' => 'Per Kategori',
                'heading' => ['Rekap per Kelompok & Kategori', $label],
                'headers' => ['Kelompok', 'Kategori', ...$months, 'Total'],
                'rows' => $matrixRows,
                'money_from' => 2,
            ],
        ]);
    }

    private function exportCategories(Request $request, ExcelExportService $excel): StreamedResponse
    {
        [$start, $end] = $this->period($request);
        $rows = $this->report->categoryRanking($start, $end)->map(fn ($r) => [
            $r['rank'], $r['code'], $r['name'], $r['group'], $r['count'], $r['percentage'] / 100, $r['monthly_average'], $r['total'],
        ])->all();

        return $excel->download("analisa-kategori-{$start->format('Ymd')}-{$end->format('Ymd')}.xlsx", [[
            'title' => 'Pengeluaran per Kategori',
            'heading' => ['Pengeluaran Operasional per Kategori', $this->periodLabel($start, $end, null)],
            'headers' => ['Peringkat', 'Kode', 'Kategori', 'Kelompok', 'Jml Transaksi', '% dari Total', 'Rata-rata / Bulan', 'Total'],
            'rows' => $rows,
            'money_from' => 6,
        ]]);
    }

    private function exportSecretariat(Request $request, ExcelExportService $excel): StreamedResponse
    {
        [$cashAccountId, $startPeriod, $endPeriod] = $this->secretariatParams($request);
        $data = $this->fundRequests->periodReport($cashAccountId, $startPeriod, $endPeriod);
        $account = CashAccount::find($cashAccountId)?->name;
        $heading = ["Laporan Pemakaian Dana {$account}", "Periode {$startPeriod} s/d {$endPeriod}"];

        return $excel->download("laporan-sekretariat-{$startPeriod}-{$endPeriod}.xlsx", [
            [
                'title' => 'Ringkasan',
                'heading' => $heading,
                'headers' => ['Periode', 'Nomor', 'Status', 'Sisa Dibawa', 'Pengajuan', 'Ditransfer', 'Realisasi LPJ', 'Selisih', 'Saldo Akhir'],
                'rows' => collect($data['requests'])->map(fn ($r) => [
                    $r['period'], $r['number'], $r['status_label'], $r['carry_over'], $r['requested'], $r['transferred'], $r['reported'], $r['difference'], $r['closing_balance'],
                ])->all(),
                'money_from' => 3,
            ],
            [
                'title' => 'Per Kategori',
                'heading' => $heading,
                'headers' => ['Kategori', ...$data['periods'], 'Total'],
                'rows' => collect($data['by_category'])->map(fn ($c) => [$c['category'], ...array_values($c['periods']), $c['total']])->all(),
                'money_from' => 1,
            ],
            [
                'title' => 'Rincian Nota',
                'heading' => $heading,
                'headers' => ['Periode', 'Pengajuan', 'Tanggal', 'Kategori', 'Uraian', 'Qty', 'Satuan', 'Harga Satuan', 'Jumlah'],
                'rows' => collect($data['items'])->map(fn ($i) => [
                    $i['period'], $i['request_number'], $i['date'], (string) $i['category'], $i['description'], $i['qty'], (string) $i['unit'], $i['unit_price'], $i['amount'],
                ])->all(),
                'money_from' => 7,
            ],
        ]);
    }

    private function summaryData(Carbon $start, Carbon $end, ?int $cashAccountId): array
    {
        $totals = $this->report->totals($start, $end, $cashAccountId);
        $opening = $this->report->balance($cashAccountId, $start);

        return [
            'period' => $this->periodArray($start, $end),
            'opening_balance' => $opening,
            ...$totals,
            'closing_balance' => round($opening + $totals['change'], 2),
            'categories' => $this->report->byCategory($start, $end, $cashAccountId),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: ?int} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
        ]);

        return [
            isset($data['start_date']) ? Carbon::parse($data['start_date'])->startOfDay() : now()->startOfMonth(),
            isset($data['end_date']) ? Carbon::parse($data['end_date'])->endOfDay() : now()->endOfMonth(),
            isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null,
        ];
    }

    /** Rentang bulan (default 12 bulan terakhir), maksimal 36 bulan. @return array{0: Carbon, 1: Carbon, 2: ?int} */
    private function monthRange(Request $request): array
    {
        $data = $request->validate([
            'start_period' => ['nullable', 'date_format:Y-m'],
            'end_period' => ['nullable', 'date_format:Y-m'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
        ]);

        $end = isset($data['end_period']) ? Carbon::createFromFormat('Y-m-d', $data['end_period'].'-01')->endOfMonth() : now()->endOfMonth();
        $start = isset($data['start_period']) ? Carbon::createFromFormat('Y-m-d', $data['start_period'].'-01')->startOfDay() : $end->copy()->startOfMonth()->subMonths(11);

        abort_if($start->gt($end), 422, 'Periode awal harus sebelum periode akhir.');
        abort_if($start->diffInMonths($end) > 36, 422, 'Rentang maksimal 36 bulan.');

        return [$start, $end, isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function secretariatParams(Request $request): array
    {
        $data = $request->validate([
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'start_period' => ['nullable', 'date_format:Y-m'],
            'end_period' => ['nullable', 'date_format:Y-m'],
        ]);

        return [
            (int) $data['cash_account_id'],
            $data['start_period'] ?? now()->startOfYear()->format('Y-m'),
            $data['end_period'] ?? now()->format('Y-m'),
        ];
    }

    private function periodArray(Carbon $start, Carbon $end): array
    {
        return ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];
    }

    private function periodLabel(Carbon $start, Carbon $end, ?int $cashAccountId): string
    {
        $account = $cashAccountId ? CashAccount::find($cashAccountId)?->name : 'Semua kas';

        return "Periode {$start->format('d/m/Y')} s/d {$end->format('d/m/Y')} · {$account}";
    }
}
