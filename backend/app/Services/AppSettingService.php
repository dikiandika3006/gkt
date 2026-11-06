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

    /** Setting berupa id kas. */
    public const CASH_KEYS = [self::DASHBOARD_CASH_ACCOUNT, self::MUTATION_CASH_ACCOUNT, self::MUTATION_FALLBACK_CASH_ACCOUNT];

    /** Setting berupa teks: kop & penandatangan laporan cetak. */
    public const TEXT_KEYS = [
        'report_church_name',
        'report_signer_left_title',
        'report_signer_left_name',
        'report_signer_right_title',
        'report_signer_right_name',
    ];

    public const KEYS = [...self::CASH_KEYS, ...self::TEXT_KEYS];

    /** @return array<string, int|string|null> */
    public function get(): array
    {
        return [
            ...collect(self::CASH_KEYS)->mapWithKeys(fn (string $key) => [$key => $this->cashAccountSetting($key)])->all(),
            ...collect(self::TEXT_KEYS)->mapWithKeys(fn (string $key) => [$key => AppSetting::valueOf($key)])->all(),
        ];
    }

    /** @param array<string, int|string|null> $data (sudah tervalidasi; hanya key yang dikirim yang diubah) */
    public function save(array $data): void
    {
        DB::transaction(function () use ($data) {
            foreach (self::CASH_KEYS as $key) {
                if (array_key_exists($key, $data)) {
                    AppSetting::putValue($key, $data[$key] === null ? null : (int) $data[$key]);
                }
            }
            foreach (self::TEXT_KEYS as $key) {
                if (array_key_exists($key, $data)) {
                    AppSetting::putValue($key, trim((string) $data[$key]) ?: null);
                }
            }
        });
    }

    /** Kop & penandatangan untuk laporan cetak, dengan nilai bawaan. */
    public function reportHeader(): array
    {
        return [
            'church_name' => AppSetting::valueOf('report_church_name') ?: 'GKT Sinai',
            'signers' => [
                ['title' => AppSetting::valueOf('report_signer_left_title') ?: 'Bendahara', 'name' => AppSetting::valueOf('report_signer_left_name')],
                ['title' => AppSetting::valueOf('report_signer_right_title') ?: 'Ketua Majelis', 'name' => AppSetting::valueOf('report_signer_right_name')],
            ],
        ];
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
