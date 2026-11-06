<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BudgetService;
use App\Services\ExcelExportService;
use App\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laporan tambahan: Realisasi Anggaran, Posisi Dana, Titipan & Pinjaman. */
class ExtendedReportController extends Controller
{
    public function __construct(private FinanceReportService $report, private BudgetService $budgets) {}

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
                $rows[] = [(string) ($r['category']['code'] ?? ''), (string) ($r['category']['name'] ?? ''), $r['amount'], $r['budget_to_date'], $r['month_actual'], $r['actual'], $r['remaining'], $r['percentage'] / 100];
            }
            $t = $data['totals'][$type];
            $rows[] = ['', 'Jumlah', $t['amount'], $t['budget_to_date'], $t['month_actual'], $t['actual'], $t['remaining'], $t['amount'] > 0 ? $t['actual'] / $t['amount'] : 0];
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
