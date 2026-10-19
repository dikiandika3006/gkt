<?php

namespace App\Enums;

enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Pemasukan',
            self::Expense => 'Pengeluaran',
        };
    }

    /** Prefix nomor transaksi: KM = Kas Masuk, KK = Kas Keluar. */
    public function numberPrefix(): string
    {
        return match ($this) {
            self::Income => 'KM',
            self::Expense => 'KK',
        };
    }
}
