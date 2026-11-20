<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master nomor perpuluhan jemaat. Hanya referensi saran nama; mutasi & rekap tetap menyimpan teks bebas.
        Schema::create('tithe_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('code')->unique();
            $table->string('name');
            $table->string('alias', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Hak awal modul Nomor Perpuluhan (nama lengkap = data pribadi): bendahara kelola; viewer & sekretariat tidak.
        $now = now();
        DB::table('role_permissions')->insertOrIgnore(array_map(fn ($r) => [...$r, 'module' => 'tithe_members', 'created_at' => $now, 'updated_at' => $now], [
            ['role' => 'bendahara', 'level' => 'manage'],
            ['role' => 'viewer', 'level' => 'none'],
            ['role' => 'sekretariat', 'level' => 'none'],
        ]));
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'tithe_members')->delete();
        Schema::dropIfExists('tithe_members');
    }
};
