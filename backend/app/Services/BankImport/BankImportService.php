<?php

namespace App\Services\BankImport;

use App\Enums\CategoryNature;
use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Services\FinanceReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Import mutasi rekening bank: baca file → saran kategori → simpan sebagai transaksi. */
class BankImportService
{
    /** Aturan kata kunci (regex atas keterangan bank, huruf besar) → nama kategori. Urutan = prioritas. */
    private const KEYWORD_RULES = [
        ['/BIAYA ADM/', 'Biaya Administrasi Bank', 'Admin'],
        ['/BIAYA TXN/', 'Biaya Administrasi Bank', 'Biaya Transfer'],
        ['/KR OTOMATIS.*QR/', 'Persembahan Syukur', 'QRIS'],
        ['/BUNGA/', 'Pendapatan Bunga', null],
        ['/TUNJANGAN PEKERJA|TUNJANGAN CS/', 'Tunjangan Pekerja Gereja', null],
        ['/TUNJANGAN HT|DAPEN/', 'Tunjangan Hamba Tuhan', null],
        ['/PERPUL|PERSEPULUHAN|PERPULUHAN|\b1\/10\b/', 'Perpuluhan', null],
        ['/G1000S|1000\s*\/?\s*HARI|1000 SEHARI/', 'Persembahan PI Misi', null],
        ['/SYUKUR/', 'Persembahan Syukur', null],
    ];

    public function __construct(private BcaCsvParser $parser, private FinanceReportService $report) {}

    public function preview(string $content, ?int $cashAccountId = null): array
    {
        $parsed = $this->parser->parse($content);
        $account = $cashAccountId ? CashAccount::findOrFail($cashAccountId) : $this->detectAccount($parsed['account_number']);
        $rows = collect($parsed['rows']);

        $categories = Category::where('is_active', true)->where('nature', '!=', CategoryNature::Transfer->value)->get();
        $recurring = RecurringExpense::where('is_active', true)->get();

        $imported = $account
            ? Transaction::where('cash_account_id', $account->id)->whereIn('bank_ref', $rows->pluck('key'))->pluck('bank_ref')->flip()
            : collect();
        $lastManualDate = $account
            ? Transaction::where('cash_account_id', $account->id)->whereNull('bank_ref')->max('date')
            : null;

        $previewRows = $rows->map(function (array $row) use ($account, $imported, $lastManualDate, $categories, $recurring) {
            [$status, $reason] = $this->status($row, $account, $imported, $lastManualDate);

            return [...$row, 'status' => $status, 'status_reason' => $reason, 'lines' => $this->suggest($row, $account, $categories, $recurring)];
        });

        $first = $rows->first();
        $last = $rows->last();
        $bankOpening = round($first['balance'] - ($first['type'] === TransactionType::Income->value ? $first['amount'] : -$first['amount']), 2);
        $appOpening = $account ? $this->report->balance($account->id, Carbon::parse($first['date'])) : null;

        return [
            'account_number' => $parsed['account_number'],
            'account_name' => $parsed['account_name'],
            'period' => $parsed['period'],
            'cash_account_id' => $account?->id,
            'last_manual_date' => $lastManualDate ? Carbon::parse($lastManualDate)->toDateString() : null,
            'summary' => [
                'bank_opening' => $bankOpening,
                'bank_closing' => $last['balance'],
                'app_opening' => $appOpening,
                'opening_difference' => $appOpening === null ? null : round($bankOpening - $appOpening, 2),
                // Saldo aplikasi s/d tanggal terakhir file (sebelum import), untuk proyeksi rekonsiliasi di layar.
                'app_until_last_date' => $account ? $this->report->balance($account->id, Carbon::parse($last['date'])->addDay()) : null,
            ],
            'rows' => $previewRows->values(),
        ];
    }

    /**
     * @param  list<array{key: string, date: string, type: string, amount: float|string, description: string, lines: list<array>}>  $rows
     */
    public function commit(int $cashAccountId, array $rows, int $userId): int
    {
        $categories = Category::whereIn('id', collect($rows)->pluck('lines.*.category_id')->flatten()->unique())->get()->keyBy('id');
        $this->validateRows($rows, $categories);

        return DB::transaction(function () use ($cashAccountId, $rows, $userId, $categories) {
            Transaction::lockNumbering();

            $used = Transaction::where('cash_account_id', $cashAccountId)->whereIn('bank_ref', collect($rows)->pluck('key'))->exists();
            if ($used) {
                throw ValidationException::withMessages(['rows' => 'Sebagian baris sudah pernah diimpor. Muat ulang file lalu coba lagi.']);
            }

            $count = 0;
            foreach ($rows as $row) {
                foreach ($row['lines'] as $line) {
                    $category = $categories[$line['category_id']];
                    $counterparty = trim((string) ($line['counterparty'] ?? '')) ?: null;
                    $isExpense = $row['type'] === TransactionType::Expense->value;

                    Transaction::create([
                        'date' => $row['date'],
                        'type' => $row['type'],
                        'category_id' => $category->id,
                        'cash_account_id' => $cashAccountId,
                        'amount' => $line['amount'],
                        'description' => trim((string) ($line['description'] ?? '')) ?: trim($category->name.' '.$counterparty),
                        'counterparty' => $counterparty,
                        'recurring_expense_id' => $isExpense ? ($line['recurring_expense_id'] ?? null) : null,
                        'recurring_period' => $isExpense && ! empty($line['recurring_expense_id']) ? ($line['recurring_period'] ?? substr($row['date'], 0, 7)) : null,
                        'bank_ref' => $row['key'],
                        'bank_description' => Str::limit($row['description'], 250, ''),
                        'created_by' => $userId,
                    ]);
                    $count++;
                }
            }

            return $count;
        });
    }

    private function validateRows(array $rows, Collection $categories): void
    {
        $errors = [];
        foreach ($rows as $i => $row) {
            $type = TransactionType::from($row['type']);
            $sum = round(collect($row['lines'])->sum(fn ($l) => (float) $l['amount']), 2);
            $label = 'Baris '.Carbon::parse($row['date'])->format('d/m/Y').' ('.number_format((float) $row['amount'], 0, ',', '.').')';

            if (abs($sum - round((float) $row['amount'], 2)) > 0.001) {
                $errors["rows.{$i}.lines"] = "{$label}: total pecahan tidak sama dengan nominal bank.";
            }

            foreach ($row['lines'] as $j => $line) {
                $category = $categories[$line['category_id']] ?? null;
                if (! $category || ! $category->is_active) {
                    $errors["rows.{$i}.lines.{$j}.category_id"] = "{$label}: kategori tidak aktif / tidak ditemukan.";
                } elseif (! $category->type->allows($type)) {
                    $errors["rows.{$i}.lines.{$j}.category_id"] = "{$label}: kategori {$category->name} tidak sesuai jenis transaksi.";
                } elseif ($category->nature === CategoryNature::Transfer) {
                    $errors["rows.{$i}.lines.{$j}.category_id"] = "{$label}: gunakan menu Mutasi Antar Kas untuk kategori ini.";
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function detectAccount(?string $number): ?CashAccount
    {
        $digits = preg_replace('/\D/', '', (string) $number);
        if ($digits === '') {
            return null;
        }

        return CashAccount::whereNotNull('account_number')->get()
            ->first(fn (CashAccount $a) => preg_replace('/\D/', '', $a->account_number) === $digits);
    }

    /** @return array{0: string, 1: ?string} */
    private function status(array $row, ?CashAccount $account, Collection $imported, ?string $lastManualDate): array
    {
        if ($imported->has($row['key'])) {
            return ['imported', 'Baris ini sudah pernah diimpor.'];
        }
        if (! $account) {
            return ['new', null];
        }

        $sameAmount = Transaction::where('cash_account_id', $account->id)
            ->whereNull('bank_ref')
            ->whereDate('date', $row['date'])
            ->where('type', $row['type'])
            ->where('amount', $row['amount'])
            ->exists();

        if ($sameAmount) {
            return ['possible_duplicate', 'Sudah ada transaksi dengan tanggal & nominal sama.'];
        }
        if ($lastManualDate && $row['date'] <= Carbon::parse($lastManualDate)->toDateString()) {
            return ['possible_duplicate', 'Tanggal ≤ transaksi manual terakhir ('.Carbon::parse($lastManualDate)->format('d/m/Y').'); mungkin sudah dicatat (bisa terpecah).'];
        }

        return ['new', null];
    }

    /** Saran baris (pecahan) untuk satu baris bank. */
    private function suggest(array $row, ?CashAccount $account, Collection $categories, Collection $recurring): array
    {
        $type = TransactionType::from($row['type']);
        $amount = (float) $row['amount'];
        $text = Str::upper($row['description']);

        // (a) Pola dari import sebelumnya dengan nama pengirim/penerima yang sama.
        if ($account && mb_strlen($row['name']) >= 4) {
            $ref = Transaction::where('cash_account_id', $account->id)
                ->where('type', $type->value)
                ->whereNotNull('bank_ref')
                ->where('bank_description', 'like', '%'.$row['name'].'%')
                ->latest('date')->latest('id')
                ->value('bank_ref');

            if ($ref) {
                $previous = Transaction::where('bank_ref', $ref)->orderBy('id')->get();
                $sameAmount = abs($previous->sum('amount') - $amount) < 0.001;
                $lines = ($sameAmount ? $previous : $previous->take(1))->map(fn (Transaction $t) => $this->line(
                    $t->category_id,
                    $sameAmount ? (float) $t->amount : $amount,
                    $t->counterparty,
                    $t->recurring_expense_id,
                    $row['date'],
                ));

                return $lines->values()->all();
            }
        }

        // (b) Biaya rutin: nominal sama & nama penerima mirip.
        if ($type === TransactionType::Expense) {
            $match = $recurring->first(fn (RecurringExpense $r) => abs((float) $r->amount - $amount) < 0.001 && $this->nameMatches($text, $r));
            if ($match) {
                return [$this->line($match->category_id, $amount, $match->counterparty, $match->id, $row['date'])];
            }
        }

        // (c) Aturan kata kunci.
        $counterparty = $this->guessCounterparty($row['name']);
        foreach (self::KEYWORD_RULES as [$pattern, $categoryName, $fixedName]) {
            if (! preg_match($pattern, $text)) {
                continue;
            }
            $category = $categories->first(fn (Category $c) => Str::lower($c->name) === Str::lower($categoryName) && $c->type->allows($type));
            if ($category) {
                return [$this->line($category->id, $amount, $fixedName ?? $counterparty, null, $row['date'])];
            }
        }

        // (d) Kosong, nama tetap diusulkan.
        return [$this->line(null, $amount, $counterparty, null, $row['date'])];
    }

    private function line(?int $categoryId, float $amount, ?string $counterparty, ?int $recurringId, string $date): array
    {
        return [
            'category_id' => $categoryId,
            'amount' => round($amount, 2),
            'counterparty' => $counterparty,
            'description' => '',
            'recurring_expense_id' => $recurringId,
            'recurring_period' => $recurringId ? substr($date, 0, 7) : null,
        ];
    }

    private function nameMatches(string $text, RecurringExpense $r): bool
    {
        $source = Str::upper(implode(' ', array_filter([$r->counterparty, $r->name])));
        $words = array_filter(preg_split('/[^A-Z]+/', $source), fn ($w) => strlen($w) >= 4 && ! in_array($w, ['TUNJANGAN', 'PEKERJA', 'DAPEN'], true));

        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    /** Nama dari bank terpotong 18 karakter → cari nama yang sudah dipakai di transaksi sebelumnya. */
    private function guessCounterparty(string $name): ?string
    {
        $words = array_values(array_filter(explode(' ', trim($name))));
        if (count($words) === 0) {
            return null;
        }

        $needle = implode(' ', array_slice($words, 0, 2));
        $existing = mb_strlen($needle) >= 4
            ? Transaction::whereNotNull('counterparty')->where('counterparty', 'like', "%{$needle}%")
                ->select('counterparty')->groupBy('counterparty')->orderByRaw('COUNT(*) DESC')->value('counterparty')
            : null;

        return $existing ?? Str::title(Str::lower(trim($name)));
    }
}
