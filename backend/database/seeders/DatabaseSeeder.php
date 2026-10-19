<?php

namespace Database\Seeders;

use App\Enums\CashAccountType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Administrator',
            'username' => 'gktsinai',
            'email' => 'admin@gkt.test',
            'password' => 'gktsinai',
            'role' => UserRole::Admin,
        ]);

        User::factory()->create([
            'name' => 'Bendahara',
            'username' => 'bendahara',
            'email' => 'bendahara@gkt.test',
            'role' => UserRole::Bendahara,
        ]);

        User::factory()->create([
            'name' => 'Sekretariat',
            'username' => 'sekretariat',
            'email' => 'sekretariat@gkt.test',
            'role' => UserRole::Sekretariat,
        ]);

        User::factory()->create([
            'name' => 'Majelis (Viewer)',
            'username' => 'viewer',
            'email' => 'viewer@gkt.test',
            'role' => UserRole::Viewer,
        ]);

        $this->call(ChartOfAccountsSeeder::class);

        // Saldo awal Rekening Gereja diisi oleh perintah import (gkt:import-mutasi) atau lewat menu Kas & Bank.
        CashAccount::create([
            'code' => 'BANK-01', 'name' => 'Rekening Gereja', 'type' => CashAccountType::Bank, 'bank_name' => 'Bank', 'opening_balance' => 0,
        ]);
        CashAccount::create([
            'code' => 'KAS-SEK', 'name' => 'Kas Sekretariat', 'type' => CashAccountType::Cash, 'opening_balance' => 0,
        ]);
    }
}
