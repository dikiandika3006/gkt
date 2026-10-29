<?php

namespace App\Services;

use App\Models\OfferingFund;
use App\Models\OfferingSetting;
use App\Models\OfferingWeekSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Master Pos Persembahan: daftar pos + rekening, jadwal Minggu ke-1..5, daftar kebaktian. */
class OfferingSettingService
{
    public function __construct(private OfferingReportService $reports) {}

    public function get(): array
    {
        $schedule = OfferingWeekSchedule::pluck('offering_fund_id', 'week');

        return [
            'funds' => OfferingFund::with('cashAccount:id,code,name,bank_name,account_number')
                ->withCount('lines')
                ->orderBy('sort_order')->orderBy('name')
                ->get()
                ->map(fn (OfferingFund $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'cash_account_id' => $f->cash_account_id,
                    'bank_name' => $f->bank_name,
                    'account_number' => $f->account_number,
                    'account_holder' => $f->account_holder,
                    'is_active' => $f->is_active,
                    'account' => $f->accountLabel(),
                    'used' => $f->lines_count > 0,
                ])->values(),
            'schedule' => collect(range(1, 5))->mapWithKeys(fn (int $w) => [$w => $schedule[$w] ?? null]),
            'services' => $this->reports->services(),
        ];
    }

    /**
     * Simpan semua pengaturan sekaligus. Pos baru dikirim tanpa id dengan `key` sementara;
     * jadwal merujuk pos lewat id (pos lama) atau key (pos baru).
     *
     * @param  array{funds: array<int, array<string, mixed>>, schedule: array<int|string, int|string|null>, services: list<string>}  $data
     */
    public function save(array $data): void
    {
        DB::transaction(function () use ($data) {
            $keep = collect($data['funds'])->pluck('id')->filter()->all();
            $removed = OfferingFund::whereNotIn('id', $keep)->withCount('lines')->get();
            if ($removed->contains(fn (OfferingFund $f) => $f->lines_count > 0)) {
                throw ValidationException::withMessages(['funds' => 'Pos yang sudah dipakai di rekap tidak bisa dihapus. Nonaktifkan saja.']);
            }
            $removed->each->delete();

            $keyToId = [];
            foreach (array_values($data['funds']) as $i => $row) {
                $fields = [
                    'name' => $row['name'],
                    'cash_account_id' => $row['cash_account_id'] ?? null,
                    // Rekening di sistem → detail bank luar tidak dipakai.
                    'bank_name' => empty($row['cash_account_id']) ? ($row['bank_name'] ?? null) : null,
                    'account_number' => empty($row['cash_account_id']) ? ($row['account_number'] ?? null) : null,
                    'account_holder' => empty($row['cash_account_id']) ? ($row['account_holder'] ?? null) : null,
                    'is_active' => $row['is_active'] ?? true,
                    'sort_order' => $i,
                ];
                $fund = isset($row['id']) ? tap(OfferingFund::findOrFail($row['id']))->update($fields) : OfferingFund::create($fields);
                $keyToId[(string) ($row['key'] ?? $fund->id)] = $fund->id;
                $keyToId[(string) $fund->id] = $fund->id;
            }

            foreach (range(1, 5) as $week) {
                $ref = $data['schedule'][$week] ?? $data['schedule'][(string) $week] ?? null;
                $fundId = $ref === null || $ref === '' ? null : ($keyToId[(string) $ref] ?? null);
                if ($ref !== null && $ref !== '' && $fundId === null) {
                    throw ValidationException::withMessages(["schedule.{$week}" => "Pos untuk Minggu ke-{$week} tidak ditemukan."]);
                }
                OfferingWeekSchedule::updateOrCreate(['week' => $week], ['offering_fund_id' => $fundId]);
            }

            OfferingSetting::putValue('services', array_values(array_filter(array_map('trim', $data['services']))));
        });
    }
}
