<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\CashAccountPocket;
use App\Models\Transaction;

/**
 * Aturan pocket: rekening ber-pocket wajib mengisi pocket miliknya; rekening biasa tanpa pocket;
 * saldo pocket tidak boleh menjadi minus karena perubahan transaksi.
 */
class PocketService
{
    public function __construct(private FinanceReportService $report) {}

    /** Pesan error bila kombinasi kas + pocket tidak valid, null bila valid. */
    public function membershipError(int $cashAccountId, mixed $pocketId, ?int $currentPocketId = null): ?string
    {
        $account = CashAccount::find($cashAccountId);
        if (! $account) {
            return null;
        }

        if (! $account->has_pockets) {
            return filled($pocketId) ? 'Rekening ini tidak memakai pocket.' : null;
        }

        if (blank($pocketId)) {
            return "Pocket wajib dipilih untuk rekening {$account->name}.";
        }

        $pocket = CashAccountPocket::where('cash_account_id', $account->id)->find((int) $pocketId);
        if (! $pocket) {
            return 'Pocket tidak ditemukan di rekening ini.';
        }

        // Pocket nonaktif tetap boleh untuk transaksi lama yang memang sudah memakainya.
        if (! $pocket->is_active && $pocket->id !== $currentPocketId) {
            return "Pocket {$pocket->name} sudah nonaktif.";
        }

        return null;
    }

    /**
     * Cek saldo pocket setelah perubahan. $changes = [pocketId => efek bertanda baru] untuk pocket yang terdampak;
     * transaksi lama ($exceptTransactionId / $exceptTransferGroup) dianggap sudah dihapus.
     * Ditolak hanya bila saldo akhir minus DAN lebih buruk dari saldo sekarang.
     */
    public function shortfallError(array $changes, ?int $exceptTransactionId = null, ?string $exceptTransferGroup = null): ?string
    {
        foreach ($changes as $pocketId => $signedAmount) {
            $current = $this->report->pocketBalance((int) $pocketId);
            $after = round($this->report->pocketBalance((int) $pocketId, $exceptTransactionId, $exceptTransferGroup) + $signedAmount, 2);

            if ($after < 0 && $after < $current) {
                $name = CashAccountPocket::whereKey($pocketId)->value('name');

                return "Saldo pocket {$name} tidak cukup (sisa Rp ".number_format(max($current, 0), 2, ',', '.').').';
            }
        }

        return null;
    }

    /** Efek bertanda transaksi (pemasukan +, pengeluaran −). */
    public static function signed(TransactionType|string $type, float|string $amount): float
    {
        $type = $type instanceof TransactionType ? $type : TransactionType::from($type);

        return $type === TransactionType::Income ? (float) $amount : -(float) $amount;
    }

    /**
     * Efek perubahan satu transaksi biasa ke pocket: pocket lama kehilangan efek lama (lewat except), pocket baru mendapat efek baru.
     *
     * @return array<int, float>
     */
    public static function transactionChanges(?Transaction $existing, ?int $newPocketId, TransactionType|string|null $newType, float|string|null $newAmount): array
    {
        $changes = [];
        if ($existing?->pocket_id) {
            $changes[$existing->pocket_id] = 0.0;
        }
        if ($newPocketId && $newType !== null && $newAmount !== null) {
            $changes[$newPocketId] = ($changes[$newPocketId] ?? 0.0) + self::signed($newType, $newAmount);
        }

        return $changes;
    }
}
