<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashAccountPocket;
use Illuminate\Support\Facades\DB;

/** Simpan kas/rekening beserta pocket-nya. Saldo awal rekening ber-pocket = Σ saldo awal pocket. */
class CashAccountService
{
    public function __construct(private FinanceReportService $report) {}

    /** @param  array<string, mixed>  $data  hasil validasi CashAccountRequest */
    public function save(array $data, ?CashAccount $account = null): CashAccount
    {
        return DB::transaction(function () use ($data, $account) {
            $pockets = $data['pockets'] ?? [];
            unset($data['pockets']);

            $hasPockets = (bool) ($data['has_pockets'] ?? false);
            if ($hasPockets) {
                $data['opening_balance'] = round(array_sum(array_map(fn ($p) => (float) $p['opening_balance'], $pockets)), 2);
            }

            $account ??= new CashAccount;
            $account->fill($data)->save();

            if ($hasPockets) {
                $this->syncPockets($account, $pockets);
            } elseif ($account->pockets()->exists()) {
                // Pocket belum pernah dipakai (dicek di request) → aman dihapus.
                $account->pockets()->get()->each->delete();
            }

            return $account;
        });
    }

    /** @param  list<array{id?: ?int, name: string, opening_balance: float|string, is_active?: bool}>  $pockets */
    private function syncPockets(CashAccount $account, array $pockets): void
    {
        $existing = $account->pockets()->get()->keyBy('id');
        $keptIds = [];
        $loweredIds = [];

        foreach (array_values($pockets) as $i => $row) {
            $attributes = [
                'name' => trim($row['name']),
                'opening_balance' => $row['opening_balance'],
                'is_active' => $row['is_active'] ?? true,
                'sort_order' => $i,
            ];

            $pocket = ! empty($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($pocket && (float) $row['opening_balance'] < (float) $pocket->opening_balance) {
                $loweredIds[] = $pocket->id;
            }
            $pocket ? $pocket->update($attributes) : $pocket = $account->pockets()->create($attributes);
            $keptIds[] = $pocket->id;
        }

        $existing->except($keptIds)->each(function (CashAccountPocket $pocket) {
            abort_if(
                $pocket->transactions()->withTrashed()->exists(),
                422,
                "Pocket {$pocket->name} sudah dipakai transaksi, jadi tidak bisa dihapus. Nonaktifkan saja."
            );
            $pocket->delete();
        });

        // Saldo awal pocket tidak boleh diturunkan sampai saldo pocket jadi minus.
        $mutations = $this->report->mutationsPerPocket($account->id);
        foreach ($account->pockets()->whereKey($loweredIds)->get() as $pocket) {
            $balance = round((float) $pocket->opening_balance + (float) ($mutations[$pocket->id] ?? 0), 2);
            abort_if($balance < 0, 422, "Saldo pocket {$pocket->name} akan menjadi minus (".number_format($balance, 2, ',', '.').'). Periksa saldo awalnya.');
        }
    }
}
