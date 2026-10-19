<?php

namespace App\Enums;

/** Status informatif pengajuan dana (tanpa approval). */
enum FundRequestStatus: string
{
    case Submitted = 'diajukan';
    case Transferred = 'ditransfer';
    case PartiallyReported = 'lpj_sebagian';
    case Completed = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Diajukan',
            self::Transferred => 'Sudah Ditransfer',
            self::PartiallyReported => 'LPJ Sebagian',
            self::Completed => 'Selesai',
        };
    }
}
