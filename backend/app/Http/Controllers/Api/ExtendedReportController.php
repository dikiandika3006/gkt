<?php

namespace App\Http\Controllers\Api;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Services\AppSettingService;
use App\Services\BudgetService;
use App\Services\ExcelExportService;
use App\Services\FinanceReportService;
use App\Services\OfferingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laporan tambahan: Realisasi Anggaran, Posisi Dana, Titipan & Pinjaman, Perbandingan, Tren Persembahan, Rekap Pemberi, LPJ Tahunan. */
class ExtendedReportController extends Controller
{
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(private FinanceReportService $report, private BudgetService $budgets, private OfferingAnalysisService $offerings) {}

    public function comparison(Request $request): JsonResponse
    {
        return response()->json($this->report->yearComparison($this->year($request)));
    }

    public function offeringTrend(Request $request): JsonResponse
    {
        [$start, $end] = $this->range($request);

        return response()->json([
            'period' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            ...$this->offerings->weeklyTrend($start, $end),
        ]);
    }

    /** Data pribadi pemberi persembahan — rute dijaga modul report_donors. */
    public function donors(Request $request): JsonResponse
    {
        return response()->json($this->offerings->donorRecap($this->year($request)));
    }

    /** LPJ Tahunan untuk Rapat Jemaat: gabungan rekap 12 bulan, anggaran, posisi dana, dana khusus, titipan & pinjaman. */
    public function annual(Request $request, AppSettingService $settings): JsonResponse
    {
        return response()->json([...$this->annualData($this->year($request)), 'header' => $settings->reportHeader()]);
    }

    private function annualData(int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->endOfYear();
        $asOf = $end->isFuture() ? now()->startOfDay() : $end->copy()->startOfDay();
        $matrix = $this->report->monthlyMatrix($start, $end);

        return [
            'year' => $year,
            'as_of' => $asOf->toDateString(),
            'summary' => [
                'opening_balance' => $matrix['opening_balance'],
                'closing_balance' => $matrix['closing_balance'],
                'months' => $matrix['months'],
                'income' => $matrix['income'],
                'expense' => $matrix['expense'],
            ],
            'budget' => $this->budgets->monthlyReport($year, 12),
            'fund_position' => $this->report->fundPosition($asOf),
            'pocket_accounts' => CashAccount::where('has_pockets', true)->orderBy('code')->get()->map(fn (CashAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'pockets' => $this->report->pocketSummary($a->id, $start, $end),
            ]),
            'deposits_loans' => $this->report->depositsLoans($start, $end),
        ];
    }

    public function budget(Request $request): JsonResponse
    {
        return response()->json($this->budgets->monthlyReport(...$this->yearMonth($request)));
    }

    public function fundPosition(Request $request): JsonResponse
    {
        return response()->json($this->report->fundPosition($this->date($request)));
    }

    public function depositsLoans(Request $request): JsonResponse
    {
        [$start, $end] = $this->range($request);

        return response()->json([
            'period' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'categories' => $this->report->depositsLoans($start, $end),
        ]);
    }

    public function export(Request $request, ExcelExportService $excel, string $name): StreamedResponse
    {
        return match ($name) {
            'budget' => $this->exportBudget($request, $excel),
            'fund-position' => $this->exportFundPosition($request, $excel),
            'deposits-loans' => $this->exportDepositsLoans($request, $excel),
            'comparison' => $this->exportComparison($request, $excel),
            'offering-trend' => $this->exportOfferingTrend($request, $excel),
            'donors' => $this->exportDonors($request, $excel),
            'annual' => $this->exportAnnual($request, $excel),
            default => abort(404, 'Laporan tidak dikenal.'),
        };
    }

    private function exportBudget(Request $request, ExcelExportService $excel): StreamedResponse
    {
        [$year, $month] = $this->yearMonth($request);
        $data = $this->budgets->monthlyReport($year, $month);
        $rows = [];
        foreach (['income' => 'PENERIMAAN', 'expense' => 'PENGELUARAN'] as $type => $label) {
            $rows[] = [$label, '', null, null, null, null, null, null];
            foreach ($data['rows']->where('type', $type) as $r) {
                $rows[] = [(string) ($r['category']['code'] ?? ''), (string) ($r['category']['name'] ?? ''), $r['amount'], $r['budget_to_date'], $r['month_actual'], $r['actual'], $r['remaining'], self::percent($r['percentage'])];
            }
            $t = $data['totals'][$type];
            $rows[] = ['', 'Jumlah', $t['amount'], $t['budget_to_date'], $t['month_actual'], $t['actual'], $t['remaining'], self::percent($t['amount'] > 0 ? $t['actual'] / $t['amount'] * 100 : null)];
            $rows[] = ['', '', null, null, null, null, null, null];
        }

        return $excel->download("realisasi-anggaran-{$data['month']}.xlsx", [[
            'title' => 'Realisasi Anggaran',
            'heading' => ['Realisasi Anggaran '.$year, 's/d bulan '.$data['month']],
            'headers' => ['Kode', 'Kategori', 'Anggaran Setahun', 'Anggaran s/d Bulan Ini', 'Realisasi Bulan Ini', 'Realisasi s/d Bulan Ini', 'Sisa Anggaran', '% Terpakai'],
            'rows' => $rows,
            'money_from' => 2,
        ]]);
    }

    private function exportFundPosition(Request $request, ExcelExportService $excel): StreamedResponse
    {
        $data = $this->report->fundPosition($this->date($request));
        $rows = [
            ['Total uang di semua kas & bank', $data['total']],
            ['Dikurangi: dana khusus (rekening ber-pocket)', -$data['special_funds']],
            ['Dikurangi: titipan belum disalurkan', -$data['deposits']],
            ['Dikurangi: utang pinjaman', -$data['loan_debt']],
            ['DANA BEBAS (OPERASIONAL)', $data['free_funds']],
            ['', null],
            ['Piutang (dipinjamkan, belum kembali)', $data['loan_receivable']],
            ['', null],
            ['SALDO PER KAS', null],
            ...collect($data['accounts'])->map(fn ($a) => [$a['name'], $a['balance']])->all(),
        ];
        foreach ($data['pockets'] as $acc) {
            $rows[] = ['', null];
            $rows[] = ['DANA KHUSUS · '.$acc['name'], null];
            foreach ($acc['pockets'] as $p) {
                $rows[] = ['  '.$p['name'], $p['balance']];
            }
        }

        return $excel->download("posisi-dana-{$data['date']}.xlsx", [[
            'title' => 'Posisi Dana',
            'heading' => ['Posisi Dana', 'Per '.Carbon::parse($data['date'])->format('d/m/Y')],
            'headers' => ['Uraian', 'Jumlah'],
            'rows' => $rows,
            'money_from' => 1,
        ]]);
    }

    private function exportDepositsLoans(Request $request, ExcelExportService $excel): StreamedResponse
    {
        [$start, $end] = $this->range($request);
        $rows = [];
        foreach ($this->report->depositsLoans($start, $end) as $cat) {
            $rows[] = [$cat['name'], '', '', '', $cat['opening'], $cat['income'], $cat['expense'], $cat['closing']];
            foreach ($cat['people'] as $p) {
                $rows[] = ['', $p['name'], '', '', $p['opening'], $p['income'], $p['expense'], $p['closing']];
                foreach ($p['transactions'] as $t) {
                    $rows[] = ['', '', $t['date'], $t['description'].' · '.$t['cash_account'], null, $t['income'] ?: null, $t['expense'] ?: null, null];
                }
            }
            $rows[] = ['', '', '', '', null, null, null, null];
        }

        return $excel->download("titipan-pinjaman-{$start->format('Ymd')}-{$end->format('Ymd')}.xlsx", [[
            'title' => 'Titipan & Pinjaman',
            'heading' => ['Titipan & Pinjaman', "Periode {$start->format('d/m/Y')} s/d {$end->format('d/m/Y')}"],
            'headers' => ['Kategori', 'Nama', 'Tanggal', 'Keterangan', 'Saldo Awal', 'Masuk', 'Keluar', 'Sisa'],
            'rows' => $rows,
            'money_from' => 4,
        ]]);
    }

    private function exportComparison(Request $request, ExcelExportService $excel): StreamedResponse
    {
        $data = $this->report->yearComparison($this->year($request));
        [$y, $p] = [$data['year'], $data['previous_year']];
        // Δ% sebagai teks agar tidak ikut format rupiah.
        $pct = fn (float $now, float $before) => self::percent($before != 0 ? ($now - $before) / abs($before) * 100 : null);

        $monthRows = $data['months']->map(fn ($m) => [
            self::MONTHS[$m['month'] - 1], $m['prev_income'], $m['income'], $pct($m['income'], $m['prev_income']),
            $m['prev_expense'], $m['expense'], $pct($m['expense'], $m['prev_expense']),
        ])->all();
        $t = $data['totals'];
        $monthRows[] = ['Jumlah', $t['prev_income'], $t['income'], $pct($t['income'], $t['prev_income']), $t['prev_expense'], $t['expense'], $pct($t['expense'], $t['prev_expense'])];

        $groupRows = [];
        foreach (['income' => 'PENERIMAAN', 'expense' => 'PENGELUARAN'] as $type => $label) {
            $groupRows[] = [$label, null, null, null];
            foreach ($data['groups'][$type] as $g) {
                $groupRows[] = [$g['group'], $g['prev_total'], $g['total'], $pct($g['total'], $g['prev_total'])];
            }
        }

        return $excel->download("perbandingan-{$p}-{$y}.xlsx", [
            ['title' => 'Per Bulan', 'heading' => ["Perbandingan {$p} vs {$y}", 'Operasional kas umum'], 'headers' => ['Bulan', "Penerimaan {$p}", "Penerimaan {$y}", 'Δ %', "Pengeluaran {$p}", "Pengeluaran {$y}", 'Δ %'], 'rows' => $monthRows, 'money_from' => 1],
            ['title' => 'Per Kelompok', 'heading' => ["Perbandingan {$p} vs {$y}", 'Per kelompok kategori'], 'headers' => ['Kelompok', (string) $p, (string) $y, 'Δ %'], 'rows' => $groupRows, 'money_from' => 1],
        ]);
    }

    private function exportOfferingTrend(Request $request, ExcelExportService $excel): StreamedResponse
    {
        [$start, $end] = $this->range($request);
        $data = $this->offerings->weeklyTrend($start, $end);
        $keys = array_column($data['sections'], 'key');
        $rows = $data['weeks']->map(fn ($w) => [$w['date'], ...array_map(fn ($k) => $w[$k], $keys), $w['total']])->all();
        $rows[] = ['Jumlah', ...array_map(fn ($k) => $data['totals'][$k], $keys), $data['totals']['total']];
        $rows[] = ['Rata-rata / minggu', ...array_fill(0, count($keys), null), $data['average']];

        return $excel->download("tren-persembahan-{$start->format('Ymd')}-{$end->format('Ymd')}.xlsx", [[
            'title' => 'Tren Persembahan',
            'heading' => ['Tren Persembahan Mingguan', "Periode {$start->format('d/m/Y')} s/d {$end->format('d/m/Y')}"],
            'headers' => ['Tanggal Ibadah', ...array_column($data['sections'], 'label'), 'Total'],
            'rows' => $rows,
            'money_from' => 1,
        ]]);
    }

    private function exportDonors(Request $request, ExcelExportService $excel): StreamedResponse
    {
        abort_unless($request->user()->hasPermission(Module::ReportDonors), 403, 'Anda tidak memiliki akses untuk aksi ini.');
        $data = $this->offerings->donorRecap($this->year($request));
        $rows = $data['rows']->map(fn ($r) => [$r['name'], ...array_values($r['months']), $r['perpuluhan'], $r['syukur'], $r['total']])->all();

        return $excel->download("rekap-pemberi-{$data['year']}.xlsx", [[
            'title' => 'Rekap per Pemberi',
            'heading' => ['Rekap Persembahan per Pemberi '.$data['year'], 'RAHASIA — data pribadi jemaat'],
            'headers' => ['Nama', ...array_map(fn ($m) => substr($m, 0, 3), self::MONTHS), 'Perpuluhan', 'Syukur', 'Total'],
            'rows' => $rows,
            'money_from' => 1,
        ]]);
    }

    private function exportAnnual(Request $request, ExcelExportService $excel): StreamedResponse
    {
        $year = $this->year($request);
        $data = $this->annualData($year);
        $heading = ["LPJ Keuangan Tahun {$year}", 'Kas umum; dana khusus terpisah'];

        $summaryRows = collect($data['summary']['months'])->map(fn ($m) => [
            self::MONTHS[(int) substr($m['month'], 5, 2) - 1], $m['opening_balance'], $m['income'], $m['expense'], $m['net'],
            $m['non_operational_in'] - $m['non_operational_out'], $m['transfer_in'] - $m['transfer_out'], $m['closing_balance'],
        ])->all();

        $groupRows = [];
        foreach (['income' => 'PENERIMAAN', 'expense' => 'PENGELUARAN'] as $type => $label) {
            $groupRows[] = [$label, '', null];
            foreach ($data['summary'][$type] as $g) {
                $groupRows[] = [$g['group'], '', $g['total']];
                foreach ($g['categories'] as $c) {
                    $groupRows[] = ['', $c['name'], $c['total']];
                }
            }
        }

        $budgetRows = $data['budget']['rows']->map(fn ($r) => [
            $r['type'] === 'income' ? 'Penerimaan' : 'Pengeluaran', (string) ($r['category']['name'] ?? ''), $r['amount'], $r['actual'], $r['remaining'], self::percent($r['percentage']),
        ])->all();

        $fp = $data['fund_position'];
        $fundRows = [
            ['Total uang di semua kas & bank', $fp['total']],
            ['Dana khusus (rekening ber-pocket)', -$fp['special_funds']],
            ['Titipan belum disalurkan', -$fp['deposits']],
            ['Utang pinjaman', -$fp['loan_debt']],
            ['DANA BEBAS', $fp['free_funds']],
        ];

        $pocketRows = [];
        foreach ($data['pocket_accounts'] as $acc) {
            foreach ($acc['pockets'] as $p) {
                $pocketRows[] = [$acc['name'], $p['name'], $p['opening_balance'], $p['income'], $p['expense'], $p['closing_balance']];
            }
        }

        $depositRows = [];
        foreach ($data['deposits_loans'] as $cat) {
            foreach ($cat['people'] as $p) {
                $depositRows[] = [$cat['name'], $p['name'], $p['opening'], $p['income'], $p['expense'], $p['closing']];
            }
        }

        return $excel->download("lpj-tahunan-{$year}.xlsx", [
            ['title' => 'Ringkasan 12 Bulan', 'heading' => $heading, 'headers' => ['Bulan', 'Saldo Awal', 'Penerimaan', 'Pengeluaran', 'Surplus/(Defisit)', 'Titipan & Pinjaman', 'Mutasi Antar Kas', 'Saldo Akhir'], 'rows' => $summaryRows, 'money_from' => 1],
            ['title' => 'Per Kategori', 'heading' => $heading, 'headers' => ['Kelompok', 'Kategori', 'Total Setahun'], 'rows' => $groupRows, 'money_from' => 2],
            ['title' => 'Realisasi Anggaran', 'heading' => $heading, 'headers' => ['Jenis', 'Kategori', 'Anggaran', 'Realisasi', 'Sisa', '% Terpakai'], 'rows' => $budgetRows, 'money_from' => 2],
            ['title' => 'Posisi Dana', 'heading' => [$heading[0], 'Per '.Carbon::parse($data['as_of'])->format('d/m/Y')], 'headers' => ['Uraian', 'Jumlah'], 'rows' => $fundRows, 'money_from' => 1],
            ['title' => 'Dana Khusus', 'heading' => $heading, 'headers' => ['Rekening', 'Pocket', 'Saldo Awal', 'Masuk', 'Keluar', 'Saldo Akhir'], 'rows' => $pocketRows, 'money_from' => 2],
            ['title' => 'Titipan & Pinjaman', 'heading' => $heading, 'headers' => ['Kategori', 'Nama', 'Saldo Awal', 'Masuk', 'Keluar', 'Sisa'], 'rows' => $depositRows, 'money_from' => 2],
        ]);
    }

    /** Persen sebagai teks (kolom Excel di area format rupiah). */
    private static function percent(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 1, ',', '.').'%';
    }

    private function year(Request $request): int
    {
        return (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);
    }

    /** @return array{0: int, 1: int} tahun & bulan (default bulan berjalan) */
    private function yearMonth(Request $request): array
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');

        return [(int) substr($month, 0, 4), (int) substr($month, 5, 2)];
    }

    private function date(Request $request): Carbon
    {
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;

        return $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
    }

    /** @return array{0: Carbon, 1: Carbon} default awal tahun s/d hari ini */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        return [
            isset($data['start_date']) ? Carbon::parse($data['start_date'])->startOfDay() : now()->startOfYear(),
            isset($data['end_date']) ? Carbon::parse($data['end_date'])->endOfDay() : now()->endOfDay(),
        ];
    }
}
