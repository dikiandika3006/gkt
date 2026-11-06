<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CashAccount;
use Illuminate\Support\Facades\DB;

/** Pengaturan umum aplikasi (menu Setting). */
class AppSettingService
{
    /** Kas yang saldonya tampil di kartu saldo dashboard (null = semua kas). */
    public const DASHBOARD_CASH_ACCOUNT = 'dashboard_cash_account_id';

    /** Kas awal di halaman Mutasi, mis. Rekening Utama. */
    public const MUTATION_CASH_ACCOUNT = 'mutation_cash_account_id';

    /** Kas awal di Mutasi bila user tidak punya akses ke kas default, mis. Kas Sekretariat. */
    public const MUTATION_FALLBACK_CASH_ACCOUNT = 'mutation_fallback_cash_account_id';

    public const KEYS = [self::DASHBOARD_CASH_ACCOUNT, self::MUTATION_CASH_ACCOUNT, self::MUTATION_FALLBACK_CASH_ACCOUNT];

    /** @return array<string, int|null> */
    public function get(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => $this->cashAccountSetting($key)])->all();
    }

    /** @param array<string, int|null> $data (sudah tervalidasi; hanya key yang dikirim yang diubah) */
    public function save(array $data): void
    {
        DB::transaction(function () use ($data) {
            foreach (self::KEYS as $key) {
                if (array_key_exists($key, $data)) {
                    AppSetting::putValue($key, $data[$key] === null ? null : (int) $data[$key]);
                }
            }
        });
    }

    public function dashboardCashAccountId(): ?int
    {
        return $this->cashAccountSetting(self::DASHBOARD_CASH_ACCOUNT);
    }

    /** Nilai setting berupa id kas; null bila kosong atau kas sudah dihapus. */
    public function cashAccountSetting(string $key): ?int
    {
        $id = AppSetting::valueOf($key);

        return $id !== null && CashAccount::whereKey($id)->exists() ? (int) $id : null;
    }
}
