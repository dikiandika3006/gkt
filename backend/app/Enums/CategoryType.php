<?php

namespace App\Enums;

/** Arah kategori. `both` = boleh dipakai pemasukan maupun pengeluaran (mis. kas komisi, refund). */
enum CategoryType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Pemasukan',
            self::Expense => 'Pengeluaran',
            self::Both => 'Dua Arah',
        };
    }

    public function allows(TransactionType $type): bool
    {
        return $this === self::Both || $this->value === $type->value;
    }
}
