<?php

namespace App\Enums;

/**
 * Modul yang hak aksesnya diatur per role di menu Hak Akses.
 * Pengguna, Log Aktivitas & Hak Akses tidak termasuk (selalu khusus admin).
 */
enum Module: string
{
    case Dashboard = 'dashboard';
    case Transactions = 'transactions';
    case Offerings = 'offerings';
    case Recurring = 'recurring';
    case Budgets = 'budgets';
    case FundRequests = 'fund_requests';
    case Reports = 'reports';
    case ReportSecretariat = 'report_secretariat';
    case Categories = 'categories';
    case CashAccounts = 'cash_accounts';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard keuangan gereja',
            self::Transactions => 'Mutasi',
            self::Offerings => 'Rekap Persembahan',
            self::Recurring => 'Biaya Rutin',
            self::Budgets => 'Anggaran',
            self::FundRequests => 'Kas Sekretariat',
            self::Reports => 'Laporan keuangan gereja',
            self::ReportSecretariat => 'Laporan Sekretariat',
            self::Categories => 'Kategori',
            self::CashAccounts => 'Kas & Bank',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Dashboard => 'Ringkasan saldo, grafik, Insight & Tanya Keuangan',
            self::Transactions => 'Pemasukan/pengeluaran, import CSV bank, mutasi antar kas',
            self::Offerings => 'Rekap persembahan mingguan',
            self::Recurring => 'Daftar biaya bulanan & status bayar',
            self::Budgets => 'Anggaran tahunan vs realisasi',
            self::FundRequests => 'Pengajuan dana & LPJ sekretariat',
            self::Reports => 'Rekap Bulanan, Ringkasan, Buku Kas, Analisa Kategori',
            self::ReportSecretariat => 'Laporan pemakaian dana Kas Sekretariat',
            self::Categories => 'Bagan akun / kategori transaksi',
            self::CashAccounts => 'Kas tunai & rekening bank',
        };
    }

    /** Modul yang hanya bisa dibaca (tidak ada aksi kelola). */
    public function viewOnly(): bool
    {
        return in_array($this, [self::Dashboard, self::Reports, self::ReportSecretariat], true);
    }

    /** @return list<PermissionLevel> */
    public function levels(): array
    {
        return $this->viewOnly()
            ? [PermissionLevel::None, PermissionLevel::View]
            : [PermissionLevel::None, PermissionLevel::View, PermissionLevel::Manage];
    }

    /** Hak default per role (sama dengan perilaku sebelum ada menu Hak Akses, kecuali sekretariat). */
    public function defaultFor(UserRole $role): PermissionLevel
    {
        return match ($role) {
            UserRole::Admin => PermissionLevel::Manage,
            UserRole::Bendahara => $this->viewOnly() ? PermissionLevel::View : PermissionLevel::Manage,
            UserRole::Viewer => PermissionLevel::View,
            UserRole::Sekretariat => match ($this) {
                self::FundRequests => PermissionLevel::Manage,
                self::ReportSecretariat => PermissionLevel::View,
                default => PermissionLevel::None,
            },
        };
    }

    /** Role yang hak aksesnya bisa diatur (admin selalu penuh). */
    public static function configurableRoles(): array
    {
        return [UserRole::Bendahara, UserRole::Sekretariat, UserRole::Viewer];
    }
}
