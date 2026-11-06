<?php

namespace App\Console\Commands;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\CashAccountPocket;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\CategoryCodeGenerator;
use App\Services\FinanceReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Import sheet "Data Mutasi" rekening ber-pocket (file "Laporan Keuangan ... (7777).xlsx").
 * Kolom: A Group Mutasi (= pocket), B Periode, C Tanggal, D Keterangan, E Debit, F Kredit, G Saldo (saldo berjalan rekening).
 * Baris "Saldo Awal ..." menjadi saldo awal pocket; baris lain menjadi transaksi.
 */
class ImportPocketCommand extends Command
{
    protected $signature = 'gkt:import-pocket
        {file : Path file .xlsx}
        {--cash-account=7777 : Kode rekening ber-pocket tujuan import}
        {--sheet=Data Mutasi : Nama sheet}
        {--dry-run : Hanya simulasi, tidak menyimpan apa pun}';

    protected $description = 'Import transaksi rekening ber-pocket dari Excel dan verifikasi saldo akhirnya';

    private const GROUP = 'Dana Pocket';

    /** @var array<string, Category> */
    private array $categoryCache = [];

    /** @var list<string> */
    private array $createdCategories = [];

    public function handle(FinanceReportService $report, CategoryCodeGenerator $codes): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $account = CashAccount::where('code', $this->option('cash-account'))->first();
        if (! $account) {
            $this->error('Rekening dengan kode '.$this->option('cash-account').' tidak ditemukan. Jalankan php artisan migrate dulu.');

            return self::FAILURE;
        }
        if ($account->transactions()->withTrashed()->exists()) {
            $this->error("Rekening {$account->name} sudah berisi transaksi. Import dibatalkan agar tidak dobel.");

            return self::FAILURE;
        }

        $this->info('Membaca file...');
        [$rows, $expectedClosing] = $this->readRows($path, $this->option('sheet'));
        $this->info(count($rows).' baris ditemukan.');

        try {
            DB::beginTransaction();

            $account->update(['has_pockets' => true]);
            $pockets = $this->pockets($account, $rows);
            $skipped = 0;

            foreach ($rows as $row) {
                if ($this->isOpening($row)) {
                    continue;
                }

                $legs = array_filter([
                    [TransactionType::Income, $row['debit']],
                    [TransactionType::Expense, $row['credit']],
                ], fn ($leg) => $leg[1] > 0);

                if ($legs === []) {
                    $skipped++;
                    $this->warn("Baris {$row['line']} dilewati (debit & kredit kosong).");

                    continue;
                }

                foreach ($legs as [$type, $amount]) {
                    Transaction::create([
                        'date' => $row['date'],
                        'type' => $type,
                        'category_id' => $this->category($row, $type, $codes)->id,
                        'cash_account_id' => $account->id,
                        'pocket_id' => $pockets[$row['pocket']]->id,
                        'amount' => $amount,
                        'description' => mb_substr($row['description'] ?: $row['pocket'], 0, 255),
                    ]);
                }
            }

            $account->update(['opening_balance' => round((float) $account->pockets()->sum('opening_balance'), 2)]);

            $closing = $report->balance($account->id);
            $pocketBalances = $report->mutationsPerPocket($account->id);
            $this->newLine();
            $this->table(['Pocket', 'Saldo awal', 'Saldo akhir'], collect($pockets)->map(fn (CashAccountPocket $p) => [
                $p->name,
                $this->money((float) $p->opening_balance),
                $this->money((float) $p->opening_balance + (float) ($pocketBalances[$p->id] ?? 0)),
            ])->values()->all());
            $this->line('Saldo akhir rekening : '.$this->money($closing));
            $this->line('Saldo di Excel       : '.$this->money($expectedClosing));

            if (abs($closing - $expectedClosing) > 0.01) {
                throw new RuntimeException('Saldo akhir tidak sama dengan Excel. Import dibatalkan.');
            }

            foreach ($pockets as $p) {
                $balance = (float) $p->opening_balance + (float) ($pocketBalances[$p->id] ?? 0);
                if ($balance < 0) {
                    $this->warn("Saldo pocket {$p->name} minus ({$this->money($balance)}). Catat mutasi antar pocket untuk menutupnya.");
                }
            }
            if ($this->createdCategories !== []) {
                $this->warn('Kategori baru dibuat: '.implode(', ', $this->createdCategories));
            }
            if ($skipped > 0) {
                $this->warn("{$skipped} baris dilewati.");
            }

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->info('Dry-run: saldo cocok, tidak ada data yang disimpan.');
            } else {
                DB::commit();
                $this->info('Import selesai, saldo cocok dengan Excel.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Buat/ambil pocket dari kolom Group Mutasi; saldo awal = baris "Saldo Awal" terakhir per pocket.
     *
     * @return array<string, CashAccountPocket>
     */
    private function pockets(CashAccount $account, array $rows): array
    {
        $openings = [];
        foreach ($rows as $row) {
            $openings[$row['pocket']] ??= 0.0;
            if ($this->isOpening($row)) {
                $openings[$row['pocket']] = round($row['debit'] - $row['credit'], 2);
            }
        }

        $pockets = [];
        $order = (int) $account->pockets()->max('sort_order');
        foreach ($openings as $name => $opening) {
            $pocket = $account->pockets()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? $account->pockets()->create(['name' => $name, 'sort_order' => ++$order]);
            $pocket->update(['opening_balance' => $opening]);
            $pockets[$name] = $pocket;
        }

        return $pockets;
    }

    private function isOpening(array $row): bool
    {
        return str_starts_with(mb_strtolower($row['description']), 'saldo awal');
    }

    /** Kategori dari keterangan; selain pola bank/pinjaman/kendaraan → "Persembahan <Pocket>" / "Biaya <Pocket>". */
    private function category(array $row, TransactionType $type, CategoryCodeGenerator $codes): Category
    {
        $text = mb_strtolower($row['description']);
        $income = $type === TransactionType::Income;

        [$name, $nature] = match (true) {
            str_contains($text, 'pinjaman') || str_starts_with($text, 'transfer dana kendaraan') => ['Pinjaman Kas Kendaraan', CategoryNature::Pinjaman],
            $income && str_starts_with($text, 'bunga bank') => ['Pendapatan Bunga', CategoryNature::Operational],
            ! $income && (str_starts_with($text, 'pajak bunga') || str_starts_with($text, 'biaya administrasi bank') || str_starts_with($text, 'biaya transfer')) => ['Biaya Administrasi Bank', CategoryNature::Operational],
            ! $income && preg_match('/servi(s|ce)|pajak kendaraan|stnk/', $text) === 1 => ['Perawatan Gedung & Kendaraan', CategoryNature::Operational],
            default => [($income ? 'Persembahan ' : 'Biaya ').$row['pocket'], CategoryNature::Operational],
        };

        if (isset($this->categoryCache[$name])) {
            return $this->categoryCache[$name];
        }

        $category = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if (! $category) {
            $categoryType = $nature === CategoryNature::Pinjaman ? CategoryType::Both : ($income ? CategoryType::Income : CategoryType::Expense);
            $group = $nature === CategoryNature::Pinjaman ? 'Pinjaman' : self::GROUP;
            $category = Category::create([
                'code' => $codes->next($group, $categoryType, $nature),
                'name' => $name,
                'group' => $group,
                'type' => $categoryType,
                'nature' => $nature,
            ]);
            $this->createdCategories[] = $name;
        } elseif (! $category->type->allows($type)) {
            $category->update(['type' => CategoryType::Both]);
        }

        return $this->categoryCache[$name] = $category;
    }

    /** @return array{0: list<array<string, mixed>>, 1: float} */
    private function readRows(string $path, string $sheetName): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $sheet = $reader->load($path)->getSheetByName($sheetName)
            ?? throw new RuntimeException("Sheet \"{$sheetName}\" tidak ditemukan.");

        $rows = [];
        $expectedClosing = 0.0;
        $highest = $sheet->getHighestDataRow();

        for ($line = 2; $line <= $highest; $line++) {
            // Sel bisa berisi rumus: pakai nilai hasil hitung terakhir yang tersimpan di file.
            $value = function (string $col) use ($sheet, $line) {
                $cell = $sheet->getCell($col.$line);

                return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
            };
            $pocket = trim((string) $value('A'));
            $rawDate = $value('C');

            // Baris sisa rumus (kosong) dilewati.
            if ($pocket === '' || $rawDate === null || $rawDate === '') {
                continue;
            }

            $balance = (float) $value('G');

            $rows[] = [
                'line' => $line,
                'pocket' => $pocket,
                'date' => $this->parseDate($rawDate, $line),
                'description' => trim((string) $value('D')),
                'debit' => round((float) $value('E'), 2),
                'credit' => round((float) $value('F'), 2),
            ];
            $expectedClosing = round($balance, 2);
        }

        return [$rows, $expectedClosing];
    }

    private function parseDate(mixed $raw, int $line): string
    {
        if (is_numeric($raw)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
        }

        try {
            return Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("Tanggal tidak valid di baris {$line}: ".var_export($raw, true));
        }
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
