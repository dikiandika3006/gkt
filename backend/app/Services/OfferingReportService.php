<?php

namespace App\Services;

use App\Enums\CashAccountType;
use App\Enums\CategoryNature;
use App\Enums\OfferingSection;
use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\OfferingFund;
use App\Models\OfferingReport;
use App\Models\OfferingReportLine;
use App\Models\OfferingSetting;
use App\Models\OfferingWeekSchedule;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rekap persembahan mingguan: total per bagian & crosscheck dengan mutasi masuk.
 * Rekap tidak membuat transaksi — transaksi berasal dari import mutasi bank.
 */
class OfferingReportService
{
    /** Daftar kebaktian bawaan bila belum diatur. */
    public const DEFAULT_SERVICES = ['KU I', 'KU II'];

    /** Rekening Utama gereja: kas kode UTAMA (atau BANK-01), jika tidak ada pakai rekening bank aktif pertama. */
    public function defaultAccount(): ?CashAccount
    {
        return CashAccount::where('code', 'UTAMA')->first()
            ?? CashAccount::where('code', 'BANK-01')->first()
            ?? CashAccount::where('type', CashAccountType::Bank)->where('is_active', true)->orderBy('id')->first();
    }

    /** Hari Minggu ke-N dalam bulan: tgl 1–7 → 1, 8–14 → 2, …, 29–31 → 5. */
    public static function weekOfMonth(Carbon|string $date): int
    {
        return intdiv(Carbon::parse($date)->day - 1, 7) + 1;
    }

    /** Pos persembahan khusus untuk tanggal ibadah tertentu (berdasarkan jadwal Minggu ke-N). */
    public function fundFor(Carbon|string $date): ?OfferingFund
    {
        return OfferingWeekSchedule::with('fund.cashAccount')->where('week', self::weekOfMonth($date))->first()?->fund;
    }

    /** @return list<string> */
    public function services(): array
    {
        $services = OfferingSetting::valueOf('services');

        return is_array($services) && $services !== [] ? array_values($services) : self::DEFAULT_SERVICES;
    }

    /**
     * Simpan rekap. Rekening tujuan ditentukan server:
     * baris pos → rekening pos; selain itu (ibadah biasa, syukur, perpuluhan, lain-lain bebas) → Rekening Utama.
     *
     * @param  array{service_date: string, period_start: string, period_end: string, notes?: ?string, lines: array<int, array<string, mixed>>}  $data
     */
    public function save(array $data, ?int $userId, ?OfferingReport $report = null): OfferingReport
    {
        $defaultId = $this->defaultAccount()?->id;
        $funds = OfferingFund::with('cashAccount')->whereIn('id', collect($data['lines'])->pluck('offering_fund_id')->filter())->get()->keyBy('id');

        return DB::transaction(function () use ($data, $userId, $report, $defaultId, $funds) {
            $fields = [
                ...collect($data)->only(['service_date', 'period_start', 'period_end', 'notes'])->all(),
                'week_of_month' => self::weekOfMonth($data['service_date']),
            ];
            $report ? $report->update($fields) : $report = OfferingReport::create([...$fields, 'created_by' => $userId]);

            $report->lines()->delete();
            foreach (array_values($data['lines']) as $i => $line) {
                $section = OfferingSection::from($line['section']);
                $fund = $section->requiresName() ? null : $funds->get($line['offering_fund_id'] ?? null);
                [$accountId, $note] = match (true) {
                    $section === OfferingSection::Pengeluaran => [null, null],
                    $fund !== null && $fund->cash_account_id !== null => [$fund->cash_account_id, null],
                    $fund !== null => [null, $fund->accountLabel()],
                    default => [$defaultId, null],
                };

                $report->lines()->create([
                    'section' => $section,
                    'offering_fund_id' => $fund?->id,
                    'label' => $line['label'] ?? null,
                    'name' => $line['name'] ?? null,
                    'amount' => $line['amount'],
                    'destination_cash_account_id' => $accountId,
                    'destination_note' => $note,
                    'sort_order' => $i,
                ]);
            }

            return $report;
        });
    }

    public function load(OfferingReport $report): OfferingReport
    {
        return $report->load(['lines.destination:id,code,name,bank_name,account_number', 'lines.fund:id,name', 'transactions.category:id,name', 'creator:id,name']);
    }

    /** Total per bagian + crosscheck. Butuh relasi lines & transactions sudah di-load. */
    public function summary(OfferingReport $report, ?int $defaultAccountId = null): array
    {
        $defaultAccountId ??= $this->defaultAccount()?->id;
        $lines = $report->lines;

        $sections = collect(OfferingSection::cases())->mapWithKeys(fn (OfferingSection $s) => [
            $s->value => round((float) $lines->where('section', $s)->sum('amount'), 2),
        ]);

        $offerings = $lines->filter(fn (OfferingReportLine $l) => $l->section->isOffering());
        $toChurch = $offerings->filter(fn (OfferingReportLine $l) => $l->destination_cash_account_id === $defaultAccountId);
        $elsewhere = $offerings->reject(fn (OfferingReportLine $l) => $l->destination_cash_account_id === $defaultAccountId);

        $expected = round((float) $toChurch->sum('amount'), 2);
        $matched = round((float) $report->transactions->sum('amount'), 2);
        $difference = round($matched - $expected, 2);

        return [
            'sections' => $sections,
            'total_offering' => round((float) $offerings->sum('amount'), 2),
            'total_expense' => $sections[OfferingSection::Pengeluaran->value],
            'expected_deposit' => $expected,
            'other_destination_total' => round((float) $elsewhere->sum('amount'), 2),
            'matched' => $matched,
            'difference' => $difference,
            'status' => match (true) {
                $report->transactions->isEmpty() => 'belum',
                abs($difference) < 0.01 => 'cocok',
                $difference < 0 => 'kurang',
                default => 'lebih',
            },
        ];
    }

    /** Rentang default crosscheck: periode persembahan rekap (mis. 23–29 Okt). @return array{0: Carbon, 1: Carbon} */
    public function defaultWindow(OfferingReport $report): array
    {
        return [$report->period_start->copy(), $report->period_end->copy()];
    }

    /** Mutasi masuk kandidat setoran: pemasukan non-mutasi-antar-kas yang belum dipakai rekap lain. */
    public function candidates(OfferingReport $report, Carbon $start, Carbon $end, int $cashAccountId): Collection
    {
        $linkedIds = $report->transactions()->pluck('transactions.id');

        $inRange = Transaction::query()
            ->with('category:id,name')
            ->where('cash_account_id', $cashAccountId)
            ->where('type', TransactionType::Income)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->whereHas('category', fn ($q) => $q->where('nature', '!=', CategoryNature::Transfer->value))
            ->whereNotIn('id', DB::table('offering_report_transactions')->where('offering_report_id', '!=', $report->id)->select('transaction_id'))
            ->get();

        // Mutasi yang sudah tertaut tetap tampil walau di luar rentang tanggal.
        $linkedOutside = Transaction::with('category:id,name')->whereIn('id', $linkedIds)->whereNotIn('id', $inRange->pluck('id'))->get();

        return $inRange->concat($linkedOutside)
            ->sortBy([['date', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'date' => $t->date->toDateString(),
                'description' => $t->description,
                'bank_description' => $t->bank_description,
                'counterparty' => $t->counterparty,
                'category' => $t->category?->name,
                'amount' => (float) $t->amount,
                'linked' => $linkedIds->contains($t->id),
            ]);
    }

    /** @param array<int, int> $ids */
    public function syncTransactions(OfferingReport $report, array $ids): void
    {
        $ids = array_values(array_unique($ids));

        $valid = Transaction::whereIn('id', $ids)
            ->where('type', TransactionType::Income)
            ->whereHas('category', fn ($q) => $q->where('nature', '!=', CategoryNature::Transfer->value))
            ->pluck('id');
        if ($valid->count() !== count($ids)) {
            throw ValidationException::withMessages(['transaction_ids' => 'Hanya mutasi masuk (bukan mutasi antar kas) yang bisa dicocokkan.']);
        }

        $usedElsewhere = DB::table('offering_report_transactions')
            ->whereIn('transaction_id', $ids)
            ->where('offering_report_id', '!=', $report->id)
            ->exists();
        if ($usedElsewhere) {
            throw ValidationException::withMessages(['transaction_ids' => 'Sebagian mutasi sudah dipakai di rekap persembahan lain.']);
        }

        $report->transactions()->sync($ids);
    }

    /**
     * Template rekap untuk tanggal ibadah: baris tetap Persembahan Ibadah
     * (Persembahan Syukur per kebaktian + QRIS → Rekening Utama, lalu pos minggu ini per kebaktian → rekening pos).
     */
    public function template(Carbon|string|null $serviceDate = null): array
    {
        $date = Carbon::parse($serviceDate ?? now());
        $main = $this->defaultAccount();
        $fund = $this->fundFor($date);
        $mainLabel = $main ? trim($main->name.' '.trim(($main->bank_name ?? '').' '.($main->account_number ?? ''))) : 'Rekening Utama';

        $lines = [];
        foreach ($this->services() as $service) {
            $lines[] = ['label' => "Persembahan Syukur {$service}", 'offering_fund_id' => null, 'account' => $mainLabel];
        }
        $lines[] = ['label' => 'Persembahan QRIS', 'offering_fund_id' => null, 'account' => $mainLabel];
        if ($fund) {
            foreach ($this->services() as $service) {
                $lines[] = ['label' => "{$fund->name} {$service}", 'offering_fund_id' => $fund->id, 'account' => $fund->accountLabel()];
            }
        }

        return [
            'service_date' => $date->toDateString(),
            'week_of_month' => self::weekOfMonth($date),
            'fund' => $fund ? ['id' => $fund->id, 'name' => $fund->name, 'account' => $fund->accountLabel()] : null,
            'ibadah_lines' => $lines,
            'funds' => OfferingFund::with('cashAccount')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (OfferingFund $f) => ['id' => $f->id, 'name' => $f->name, 'account' => $f->accountLabel()])->values(),
            'default_cash_account_id' => $main?->id,
            'main_account' => $mainLabel,
        ];
    }

    /** Ringkasan rekap dalam satu bulan (berdasarkan tanggal ibadah). */
    public function monthSummary(Collection $reports, array $summaries): array
    {
        $sections = collect(OfferingSection::cases())->mapWithKeys(fn (OfferingSection $s) => [
            $s->value => round((float) collect($summaries)->sum(fn ($x) => $x['sections'][$s->value]), 2),
        ]);

        return [
            'weeks' => $reports->count(),
            'sections' => $sections,
            'total_offering' => round((float) collect($summaries)->sum('total_offering'), 2),
            'total_expense' => round((float) collect($summaries)->sum('total_expense'), 2),
        ];
    }
}
