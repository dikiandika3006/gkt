<?php

namespace App\Services;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mutasi antar kas: selalu dua transaksi berpasangan (keluar dari kas asal, masuk ke kas tujuan)
 * dengan transfer_group yang sama. Tidak mempengaruhi pemasukan/pengeluaran operasional.
 */
class TransferService
{
    public const CATEGORY_OUT = 'TRF-KELUAR';

    public const CATEGORY_IN = 'TRF-MASUK';

    /**
     * @param  array{date: string, from_cash_account_id: int, to_cash_account_id: int, amount: float|string, description: string, reference?: ?string, fund_request_id?: ?int}  $data
     * @return Collection<int, Transaction> [keluar, masuk]
     */
    public function create(array $data, ?int $userId): Collection
    {
        return DB::transaction(function () use ($data, $userId) {
            Transaction::lockNumbering();
            $group = (string) Str::uuid();

            return collect([
                [TransactionType::Expense, $data['from_cash_account_id'], $this->category(self::CATEGORY_OUT)],
                [TransactionType::Income, $data['to_cash_account_id'], $this->category(self::CATEGORY_IN)],
            ])->map(fn (array $leg) => Transaction::create([
                'date' => $data['date'],
                'type' => $leg[0],
                'category_id' => $leg[2]->id,
                'cash_account_id' => $leg[1],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'transfer_group' => $group,
                'fund_request_id' => $data['fund_request_id'] ?? null,
                'created_by' => $userId,
            ]));
        });
    }

    /** Ubah tanggal/jumlah/keterangan kedua sisi sekaligus. */
    public function update(string $group, array $data): Collection
    {
        return DB::transaction(function () use ($group, $data) {
            $legs = $this->legs($group);
            $legs->each(fn (Transaction $t) => $t->update([
                'date' => $data['date'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
            ]));

            return $legs;
        });
    }

    public function delete(string $group): void
    {
        DB::transaction(fn () => $this->legs($group)->each->delete());
    }

    /** @return Collection<int, Transaction> */
    public function legs(string $group): Collection
    {
        $legs = Transaction::where('transfer_group', $group)->orderBy('type')->get();
        abort_if($legs->isEmpty(), 404, 'Mutasi antar kas tidak ditemukan.');

        return $legs;
    }

    /** Kategori sistem untuk mutasi antar kas (dibuat otomatis jika belum ada). */
    private function category(string $code): Category
    {
        return Category::firstOrCreate(['code' => $code], [
            'name' => $code === self::CATEGORY_OUT ? 'Mutasi Keluar (ke kas lain)' : 'Mutasi Masuk (dari kas lain)',
            'group' => 'Mutasi Antar Kas',
            'type' => $code === self::CATEGORY_OUT ? CategoryType::Expense : CategoryType::Income,
            'nature' => CategoryNature::Transfer,
        ]);
    }

    public static function ensureAccountsDiffer(int $from, int $to): void
    {
        abort_if($from === $to, 422, 'Kas asal dan kas tujuan tidak boleh sama.');
        abort_unless(CashAccount::whereIn('id', [$from, $to])->where('is_active', true)->count() === 2, 422, 'Kas tidak ditemukan atau nonaktif.');
    }
}
