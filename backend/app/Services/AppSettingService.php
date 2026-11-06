<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CashAccount;
use Illuminate\Support\Facades\DB;

/** Pengaturan umum aplikasi (menu Setting). */
class AppSettingService
{
    public const DASHBOARD_CASH_ACCOUNT = 'dashboard_cash_account_id';

    /** @return array{dashboard_cash_account_id: int|null} */
    public function get(): array
    {
        return [self::DASHBOARD_CASH_ACCOUNT => $this->dashboardCashAccountId()];
    }

    /** @param array{dashboard_cash_account_id?: int|null} $data (sudah tervalidasi) */
    public function save(array $data): void
    {
        DB::transaction(function () use ($data) {
            if (array_key_exists(self::DASHBOARD_CASH_ACCOUNT, $data)) {
                $id = $data[self::DASHBOARD_CASH_ACCOUNT];
                AppSetting::putValue(self::DASHBOARD_CASH_ACCOUNT, $id === null ? null : (int) $id);
            }
        });
    }

    /** Kas yang saldonya tampil di dashboard; null = semua kas (juga bila kas terpilih sudah dihapus). */
    public function dashboardCashAccountId(): ?int
    {
        $id = AppSetting::valueOf(self::DASHBOARD_CASH_ACCOUNT);

        return $id !== null && CashAccount::whereKey($id)->exists() ? (int) $id : null;
    }
}
