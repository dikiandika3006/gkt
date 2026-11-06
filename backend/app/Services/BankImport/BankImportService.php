<?php

namespace App\Services\BankImport;

use App\Enums\CategoryNature;
use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\CashAccountPocket;
use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Services\FinanceReportService;
use App\Services\PocketService;
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
        ['/PAJAK BUNGA/', 'Biaya Administrasi Bank', 'Pajak Bunga'],
        ['/KR OTOMATIS.*QR/', 'Persembahan Syukur', 'QRIS'],
        ['/BUNGA/', 'Pendapatan Bunga', null],
        ['/TUNJANGAN PEKERJA|TUNJANGAN CS/', 'Tunjangan Pekerja Gereja', null],
        ['/TUNJANGAN HT|DAPEN/', 'Tunjangan Hamba Tuhan', null],
        ['/PERPUL|PERSEPULUHAN|PERPULUHAN|\b1\/10\b/', 'Perpuluhan', null],
        ['/G1000S|1000\s*\/?\s*HARI|1000 SEHARI/', 'Persembahan PI Misi', null],
        ['/SYUKUR/', 'Persembahan Syukur', null],
    ];

    public function __construct(private BcaCsvParser $parser, private FinanceReportService $report) {}

    /** Preview CSV KlikBCA (format lama). */
    public function preview(string $content, ?int $cashAccountId = null): array
    {
        return $this->previewParsed($this->parser->parse($content), $cashAccountId);
    }

    /**
     * Preview dari hasil parser mana pun (BcaCsvParser / SpreadsheetMutationParser).
     * Baris boleh membawa 'pocket' (nama dari file) dan file boleh membawa 'openings' & 'detection'.
     */
    public function previewParsed(array $parsed, ?int $cashAccountId = null): array
    {
        $rows = collect($parsed['rows']);
        $account = $cashAccountId
            ? CashAccount::findOrFail($cashAccountId)
            : ($this->detectAccount($parsed['account_number']) ?? $this->detectAccountByName($rows));
        $pockets = $account?->has_pockets ? $account->pockets()->get() : collect();

        $categories = Category::where('is_active', true)->where('nature', '!=', CategoryNature::Transfer->value)->get();
        $transferNames = Category::where('nature', CategoryNature::Transfer->value)->pluck('name')->map(fn ($n) => Str::lower($n))->flip();
        $recurring = RecurringExpense::where('is_active', true)->get();

        $imported = $account
            ? Transaction::where('cash_account_id', $account->id)->whereIn('bank_ref', $rows->pluck('key'))->pluck('bank_ref')->flip()
            : collect();
        $lastManualDate = $account
            ? Transaction::where('cash_account_id', $account->id)->whereNull('bank_ref')->max('date')
            : null;

        $previewRows = $rows->map(function (array $row) use ($account, $imported, $lastManualDate, $categories, $recurring, $pockets, $transferNames) {
            [$status, $reason] = $this->status($row, $account, $imported, $lastManualDate);
            if ($status !== 'imported' && ! empty($row['category']) && $transferNames->has(Str::lower($row['category']))) {
                [$status, $reason] = ['transfer', 'Mutasi antar kas/pocket — catat lewat menu Mutasi Antar Kas.'];
            }

            return [
                ...$row,
                'pocket' => $row['pocket'] ?? null,
                'pocket_id' => $this->suggestPocket($row, $pockets),
                'status' => $status,
                'status_reason' => $reason,
                'lines' => $this->suggest($row, $account, $categories, $recurring),
            ];
        });

        $first = $rows->first();
        $last = $rows->last();
        $openings = collect($parsed['openings'] ?? []);
        $bankOpening = match (true) {
            $openings->isNotEmpty() => round((float) $openings->sum('amount'), 2),
            $first !== null && $first['balance'] !== null => round($first['balance'] - ($first['type'] === TransactionType::Income->value ? $first['amount'] : -$first['amount']), 2),
            default => null,
        };
        $appOpening = $account && $first ? $this->report->balance($account->id, Carbon::parse($first['date'])) : null;
        $pocketMutations = $account?->has_pockets ? $this->report->mutationsPerPocket($account->id) : collect();

        return [
            'account_number' => $parsed['account_number'],
            'account_name' => $parsed['account_name'],
            'period' => $parsed['period'],
            'cash_account_id' => $account?->id,
            'has_pockets' => (bool) $account?->has_pockets,
            'pockets' => $pockets->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'is_active' => $p->is_active,
                'current_balance' => round((float) $p->opening_balance + (float) ($pocketMutations[$p->id] ?? 0), 2),
            ])->values(),
            'last_manual_date' => $lastManualDate ? Carbon::parse($lastManualDate)->toDateString() : null,
            'openings' => $openings->values(),
            'detection' => $parsed['detection'] ?? null,
            'summary' => [
                'bank_opening' => $bankOpening,
                'bank_closing' => $last['balance'] ?? null,
                'app_opening' => $appOpening,
                'opening_difference' => $appOpening === null || $bankOpening === null ? null : round($bankOpening - $appOpening, 2),
                // Saldo aplikasi s/d tanggal terakhir file (sebelum import), untuk proyeksi rekonsiliasi di layar.
                'app_until_last_date' => $account && $last ? $this->report->balance($account->id, Carbon::parse($last['date'])->addDay()) : null,
            ],
            'rows' => $previewRows->values(),
        ];
    }

    /**
     * Pocket hanya disarankan bila yakin: nama dari kolom pocket file, atau tepat satu nama pocket
     * muncul di keterangan. Selain itu dikosongkan agar dipilih user saat konfirmasi.
     *
     * @param  Collection<int, CashAccountPocket>  $pockets
     */
    private function suggestPocket(array $row, Collection $pockets): ?int
    {
        if ($pockets->isEmpty()) {
            return null;
        }

        $squash = fn (string $s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
        $active = $pockets->where('is_active', true);

        if (! empty($row['pocket'])) {
            return $pockets->first(fn ($p) => $squash($p->name) === $squash($row['pocket']))?->id;
        }

        $text = ' '.mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', $row['description'])).' ';
        $matches = $active->filter(function ($p) use ($text, $squash) {
            $name = mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u', ' ', $p->name)));

            return $name !== '' && (str_contains($text, " {$name} ") || str_contains($squash($text), $squash($p->name)) && mb_strlen($squash($p->name)) >= 6);
        });

        return $matches->count() === 1 ? $matches->first()->id : null;
    }

    /**
     * @param  list<array{key: string, date: string, type: string, amount: float|string, description: string, lines: list<array>}>  $rows
     */
    public function commit(int $cashAccountId, array $rows, int $userId, bool $confirmNegative = false): int
    {
        $categories = Category::whereIn('id', collect($rows)->pluck('lines.*.category_id')->flatten()->unique())->get()->keyBy('id');
        $this->validateRows($rows, $categories);
        $this->validatePockets($cashAccountId, $rows, $confirmNegative);

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
                        'pocket_id' => $row['pocket_id'] ?? null,
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

    /** Pocket wajib & milik rekening; saldo pocket minus setelah import hanya boleh dengan konfirmasi. */
    private function validatePockets(int $cashAccountId, array $rows, bool $confirmNegative): void
    {
        $pockets = app(PocketService::class);
        $errors = [];
        $changes = [];

        foreach ($rows as $i => $row) {
            $pocketId = ! empty($row['pocket_id']) ? (int) $row['pocket_id'] : null;
            if ($error = $pockets->membershipError($cashAccountId, $pocketId)) {
                $errors["rows.{$i}.pocket_id"] = 'Baris '.Carbon::parse($row['date'])->format('d/m/Y').': '.$error;

                continue;
            }
            if ($pocketId) {
                $changes[$pocketId] = ($changes[$pocketId] ?? 0.0) + PocketService::signed($row['type'], $row['amount']);
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if ($confirmNegative || $changes === []) {
            return;
        }

        $negative = [];
        foreach ($changes as $pocketId => $change) {
            $after = round($this->report->pocketBalance($pocketId) + $change, 2);
            if ($after < 0) {
                $negative[] = CashAccountPocket::whereKey($pocketId)->value('name').' ('.number_format($after, 2, ',', '.').')';
            }
        }

        if ($negative) {
            throw ValidationException::withMessages([
                'confirm_negative' => 'Saldo pocket akan minus: '.implode(', ', $negative).'. Centang konfirmasi bila akan ditutup dengan mutasi antar pocket.',
            ]);
        }
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

    /** File export Mutasi: semua baris berkolom Kas sama → cocokkan ke nama/kode kas. */
    private function detectAccountByName(Collection $rows): ?CashAccount
    {
        $names = $rows->pluck('cash_account')->filter()->map(fn ($n) => Str::lower(trim($n)))->unique();
        if ($names->count() !== 1) {
            return null;
        }

        return CashAccount::all()->first(fn (CashAccount $a) => in_array($names->first(), [Str::lower($a->name), Str::lower((string) $a->code)], true));
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

        // (0) Kategori tertulis di file (mis. hasil export Mutasi aplikasi ini): pakai apa adanya.
        if (! empty($row['category'])) {
            $category = $categories->first(fn (Category $c) => Str::lower($c->name) === Str::lower(trim($row['category'])) && $c->type->allows($type));
            if ($category) {
                $line = $this->line($category->id, $amount, trim($row['name']) ?: null, null, $row['date']);
                $line['description'] = mb_substr($row['description'], 0, 255);

                return [$line];
            }
        }

        // Riwayat import ke nama pengirim/penerima yang sama, terbaru dulu (dikelompokkan per baris bank).
        $history = $account ? $this->history($account->id, $type, $row['name']) : collect();

        // (a) Riwayat dengan nominal sama persis: salin pola pecahan, nama & biaya rutinnya.
        $exact = $history->first(fn (Collection $lines) => abs($lines->sum('amount') - $amount) < 0.001);
        if ($exact) {
            return $exact->map(fn (Transaction $t) => $this->line($t->category_id, (float) $t->amount, $t->counterparty, $t->recurring_expense_id, $row['date']))
                ->values()->all();
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

        // (d) Riwayat nominal berbeda (cadangan): kategori & nama terakhir, tanpa biaya rutin.
        $latest = $history->first()?->first();
        if ($latest) {
            return [$this->line($latest->category_id, $amount, $latest->counterparty, null, $row['date'])];
        }

        // (e) Kosong, nama tetap diusulkan.
        return [$this->line(null, $amount, $counterparty, null, $row['date'])];
    }

    /** @return Collection<int, Collection<int, Transaction>> pecahan per baris bank, baris bank terbaru dulu. */
    private function history(int $cashAccountId, TransactionType $type, string $name): Collection
    {
        if (mb_strlen($name) < 4) {
            return collect();
        }

        $refs = Transaction::where('cash_account_id', $cashAccountId)
            ->where('type', $type->value)
            ->whereNotNull('bank_ref')
            ->where('bank_description', 'like', '%'.$name.'%')
            ->select('bank_ref')
            ->groupBy('bank_ref')
            ->orderByRaw('MAX(date) DESC, MAX(id) DESC')
            ->limit(20)
            ->pluck('bank_ref');

        $lines = Transaction::whereIn('bank_ref', $refs)->orderBy('id')->get()->groupBy('bank_ref');

        return $refs->map(fn (string $ref) => $lines[$ref])->values();
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
