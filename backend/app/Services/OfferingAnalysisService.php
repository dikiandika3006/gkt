<?php

namespace App\Services;

use App\Enums\OfferingSection;
use App\Models\OfferingReportLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Analisa dari Rekap Persembahan mingguan: tren per minggu & rekap per pemberi. */
class OfferingAnalysisService
{
    /** Bagian yang dijumlahkan sebagai persembahan (tanpa pengeluaran). */
    private const SECTIONS = [OfferingSection::Ibadah, OfferingSection::Syukur, OfferingSection::Perpuluhan, OfferingSection::Lainnya];

    /**
     * Total persembahan per minggu (tanggal ibadah) per bagian.
     *
     * @return array{weeks: Collection, totals: array<string, float>, average: float, sections: list<array{key: string, label: string}>}
     */
    public function weeklyTrend(Carbon $start, Carbon $end): array
    {
        $lines = $this->lines($start, $end)->get();
        $keys = array_map(fn (OfferingSection $s) => $s->value, self::SECTIONS);

        $weeks = $lines->groupBy(fn (OfferingReportLine $l) => $l->report->service_date->toDateString())
            ->map(function (Collection $rows, string $date) use ($keys) {
                $week = ['date' => $date];
                foreach ($keys as $key) {
                    $week[$key] = round((float) $rows->filter(fn ($l) => $l->section->value === $key)->sum('amount'), 2);
                }
                $week['total'] = round(array_sum(array_intersect_key($week, array_flip($keys))), 2);

                return $week;
            })
            ->sortKeys()
            ->values();

        $totals = [];
        foreach ([...$keys, 'total'] as $key) {
            $totals[$key] = round((float) $weeks->sum($key), 2);
        }

        return [
            'sections' => array_map(fn (OfferingSection $s) => ['key' => $s->value, 'label' => $s->label()], self::SECTIONS),
            'weeks' => $weeks,
            'totals' => $totals,
            'average' => $weeks->isEmpty() ? 0.0 : round($totals['total'] / $weeks->count(), 2),
        ];
    }

    /**
     * Persembahan syukur & perpuluhan per nama per bulan dalam setahun (data pribadi — modul report_donors).
     * Nama disatukan tanpa beda huruf besar/kecil & spasi ganda.
     */
    public function donorRecap(int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->endOfYear();
        $lines = $this->lines($start, $end)
            ->whereIn('section', [OfferingSection::Syukur->value, OfferingSection::Perpuluhan->value])
            ->whereNotNull('name')
            ->get()
            ->filter(fn (OfferingReportLine $l) => trim((string) $l->name) !== '');

        $emptyMonths = array_fill_keys(array_map(fn ($m) => str_pad((string) $m, 2, '0', STR_PAD_LEFT), range(1, 12)), 0.0);

        $rows = $lines->groupBy(fn (OfferingReportLine $l) => Str::lower(preg_replace('/\s+/', ' ', trim($l->name))))
            ->map(function (Collection $items) use ($emptyMonths) {
                $months = $emptyMonths;
                foreach ($items as $l) {
                    $m = $l->report->service_date->format('m');
                    $months[$m] = round($months[$m] + (float) $l->amount, 2);
                }
                $bySection = fn (OfferingSection $s) => round((float) $items->filter(fn ($l) => $l->section === $s)->sum('amount'), 2);

                return [
                    'name' => preg_replace('/\s+/', ' ', trim($items->first()->name)),
                    'months' => $months,
                    'perpuluhan' => $bySection(OfferingSection::Perpuluhan),
                    'syukur' => $bySection(OfferingSection::Syukur),
                    'total' => round(array_sum($months), 2),
                    'count' => $items->count(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'year' => $year,
            'rows' => $rows,
            'totals' => [
                'perpuluhan' => round((float) $rows->sum('perpuluhan'), 2),
                'syukur' => round((float) $rows->sum('syukur'), 2),
                'total' => round((float) $rows->sum('total'), 2),
                'donors' => $rows->count(),
            ],
        ];
    }

    private function lines(Carbon $start, Carbon $end)
    {
        return OfferingReportLine::query()
            ->with('report:id,service_date')
            ->whereIn('section', array_map(fn (OfferingSection $s) => $s->value, self::SECTIONS))
            ->whereHas('report', fn ($q) => $q->whereDate('service_date', '>=', $start)->whereDate('service_date', '<=', $end));
    }
}
