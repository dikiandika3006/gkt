<?php

namespace App\Console\Commands;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\FinanceReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Import sheet "Data Mutasi" dari file Excel keuangan lama.
 * Kolom: A Periode, B Tanggal, C Group, D Global, E Kode/Nama, F Keterangan, G Debit, H Kredit, I Saldo, J Note.
 */
class ImportMutasiCommand extends Command
{
    protected $signature = 'gkt:import-mutasi
        {file : Path file .xlsx}
        {--cash-account=BANK-01 : Kode kas tujuan import}
        {--sheet=Data Mutasi : Nama sheet}
        {--dry-run : Hanya simulasi, tidak menyimpan apa pun}';

    protected $description = 'Import transaksi dari sheet "Data Mutasi" (Excel keuangan lama) dan verifikasi saldo akhirnya';

    public function handle(FinanceReportService $report): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $account = CashAccount::where('code', $this->option('cash-account'))->first();
        if (! $account) {
            $this->error('Kas dengan kode '.$this->option('cash-account').' tidak ditemukan. Jalankan seeder dulu.');

            return self::FAILURE;
        }
        if ($account->transactions()->withTrashed()->exists()) {
            $this->error("Kas {$account->name} sudah berisi transaksi. Import dibatalkan agar tidak dobel.");

            return self::FAILURE;
        }

        $this->info('Membaca file...');
        [$rows, $expectedClosing] = $this->readRows($path, $this->option('sheet'));
        $this->info(count($rows).' baris ditemukan.');

        $created = [];
        $skipped = 0;

        try {
            DB::beginTransaction();

            foreach ($rows as $row) {
                if (strcasecmp($row['group'], 'Saldo') === 0) {
                    $account->update(['opening_balance' => $row['balance']]);

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

                $category = $this->category($row, $created);

                foreach ($legs as [$type, $amount]) {
                    Transaction::create([
                        'date' => $row['date'],
                        'type' => $type,
                        'category_id' => $category->id,
                        'cash_account_id' => $account->id,
                        'amount' => $amount,
                        'description' => mb_substr($row['description'] ?: trim($row['group'].' '.$row['name']), 0, 255),
                        'counterparty' => $row['name'] !== '' ? mb_substr($row['name'], 0, 255) : null,
                        'notes' => $row['note'] !== '' ? $row['note'] : null,
                    ]);
                }
            }

            $closing = $report->balance($account->id);
            $this->newLine();
            $this->line('Saldo awal      : '.$this->money((float) $account->fresh()->opening_balance));
            $this->line('Saldo akhir     : '.$this->money($closing));
            $this->line('Saldo di Excel  : '.$this->money($expectedClosing));

            if (abs($closing - $expectedClosing) > 0.01) {
                throw new RuntimeException('Saldo akhir tidak sama dengan Excel. Import dibatalkan.');
            }

            $this->printMonthly($report, $account->id, $rows);

            if ($created !== []) {
                $this->warn('Kategori baru dibuat: '.implode(', ', $created));
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

    /** @return array{0: array<int, array<string, mixed>>, 1: float} */
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
            $value = fn (string $col) => $sheet->getCell($col.$line)->getValue();
            $rawDate = $value('B');
            $group = trim((string) $value('C'));
            $debit = round((float) $value('G'), 2);
            $credit = round((float) $value('H'), 2);

            if ($rawDate === null && $group === '' && $debit == 0 && $credit == 0) {
                continue;
            }

            $balanceCell = $sheet->getCell('I'.$line);
            $balance = (float) ($balanceCell->isFormula() ? $balanceCell->getOldCalculatedValue() : $balanceCell->getValue());

            $rows[] = [
                'line' => $line,
                'date' => $this->parseDate($rawDate, $line),
                'group' => $group,
                'global' => trim((string) $value('D')),
                'name' => trim((string) $value('E')),
                'description' => trim((string) $value('F')),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($balance, 2),
                'note' => trim((string) $value('J')),
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

    /** Cocokkan kolom Group dengan nama kategori; buat baru (dua arah) jika belum ada. */
    private function category(array $row, array &$created): Category
    {
        $name = $row['group'] !== '' ? $row['group'] : 'Lain-lain (Import)';
        $category = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($category) {
            return $category;
        }

        $nature = match (mb_strtolower($row['global'])) {
            'titipan' => CategoryNature::Titipan,
            'pinjaman' => CategoryNature::Pinjaman,
            default => CategoryNature::Operational,
        };

        $created[] = $name;

        return Category::create([
            'code' => 'IMP-'.str_pad((string) (Category::where('code', 'like', 'IMP-%')->count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'group' => $row['global'] !== '' ? $row['global'] : 'Lain-lain',
            'type' => CategoryType::Both,
            'nature' => $nature,
        ]);
    }

    private function printMonthly(FinanceReportService $report, int $accountId, array $rows): void
    {
        $dates = collect($rows)->pluck('date')->sort()->values();
        $matrix = $report->monthlyMatrix(Carbon::parse($dates->first()), Carbon::parse($dates->last())->endOfMonth(), $accountId);

        $this->table(
            ['Bulan', 'Pemasukan Op.', 'Pengeluaran Op.', 'Surplus/(Defisit)', 'Titipan/Pinjaman', 'Saldo Akhir'],
            collect($matrix['months'])->map(fn ($m) => [
                $m['month'],
                $this->money($m['income']),
                $this->money($m['expense']),
                $this->money($m['net']),
                $this->money($m['non_operational_in'] - $m['non_operational_out']),
                $this->money($m['closing_balance']),
            ])->all(),
        );
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
