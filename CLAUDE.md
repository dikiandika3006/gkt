# Keuangan GKT Sinai — Aturan Kerja Wajib

Aplikasi pencatatan & pelaporan keuangan gereja GKT Sinai.

- [backend/](backend/) — Laravel 13 API (Sanctum, token Bearer). Instruksi tambahan di `backend/CLAUDE.md` tetap berlaku.
- [frontend/](frontend/) — SPA Vite + React 19 + shadcn/ui + React Query + react-hook-form/zod + Recharts.

## Perintah
- Backend: `cd backend && php artisan serve` · test: `php artisan test` (SQLite in-memory) · format: `vendor/bin/pint`
- Frontend: `cd frontend && npm run dev` · `npm run build` · `npm run lint`
- Import data Excel lama: `php artisan gkt:import-mutasi "<path.xlsx>" [--dry-run]` (sheet "Data Mutasi")

## Konsep domain
- **Kas** (`cash_accounts`): Rekening Gereja, Kas Sekretariat, dst. Saldo = saldo awal + Σmutasi.
- **Kategori** punya `group` (Global, untuk laporan), `type` (income/expense/both) dan `nature`:
  `operational` (dihitung di surplus/defisit), `titipan`, `pinjaman`, `transfer` (mutasi antar kas) — tiga terakhir TIDAK dihitung sebagai pemasukan/pengeluaran operasional.
- **Mutasi antar kas**: dua transaksi berpasangan (`transfer_group`), dibuat lewat `TransferService`.
- **Kas Sekretariat** (uang muka): Pengajuan → transfer (mutasi antar kas) → LPJ per nota (pengeluaran dari Kas Sekretariat) → saldo kas = sisa dibawa ke pengajuan berikutnya. Tanpa approval.
- **Biaya rutin** hanya anggaran; pembayaran nyata dicatat sebagai transaksi biasa yang ditautkan ke item + periode.

## Konvensi
- Teks untuk user (pesan validasi, error, label) dalam Bahasa Indonesia.
- Backend: Enum di `app/Enums`, validasi di FormRequest, output lewat JsonResource, controller di `App\Http\Controllers\Api`, otorisasi per role lewat middleware `role:` di `routes/api.php`; logika bisnis di `app/Services`.
- Query laporan harus SQL portabel (MySQL di aplikasi, SQLite di test) — jangan pakai fungsi tanggal spesifik DB.
- Operasi yang membuat >1 baris finansial wajib di dalam `DB::transaction`.
- Model finansial memakai trait `LogsActivity` (audit log).
- Frontend: satu folder per modul di `src/features/<modul>`, query bersama di `src/lib/queries.ts`, tipe di `src/types.ts`, format angka/tanggal lewat `src/lib/format.ts`.

## Kapan workflow 14 tahap di bawah ini WAJIB dipakai

Wajib dijalankan penuh untuk setiap **perubahan kode**: fitur baru, bugfix, refactor, perubahan migrasi/skema database, perubahan route/API, perubahan config yang mempengaruhi perilaku aplikasi.

Boleh dilewati (jawab langsung tanpa 14 tahap) untuk request **read-only/eksplorasi**: menampilkan isi file, menjelaskan kode, mencari lokasi bug tanpa memperbaikinya, menjawab pertanyaan konsep. Begitu jawabannya berujung pada rencana mengubah file, workflow ini mulai berlaku dari tahap 00.

Jika ragu apakah suatu request termasuk "perubahan kode" atau tidak, anggap sebagai perubahan kode dan jalankan workflow penuh.

## Tahap 00 — REQUEST INTAKE
- Tulis ulang permintaan user dengan kata-kata sendiri: apa tujuannya, apa yang dianggap "selesai".
- Tentukan scope eksplisit: file/fitur mana yang termasuk, mana yang TIDAK termasuk.
- Jika ada ambiguitas material (bukan detail kecil yang bisa diasumsikan wajar), tanyakan ke user sebelum lanjut.

## Tahap 01 — PROJECT RECON
- Jelajahi struktur yang relevan (backend: Controllers, Requests, Resources, Models, Services, Routes, Migrations; frontend: features, lib, types) sebelum menyentuh apapun.
- Ikuti pola yang sudah ada, jangan bikin gaya baru tanpa alasan kuat.

## Tahap 02 — CODE FLOW ANALYSIS
- Telusuri alur: halaman frontend → endpoint API → FormRequest → controller → service/model → resource → tampilan.

## Tahap 03 — DEPENDENCY ANALYSIS
- Cari semua pemakai kode yang akan diubah (controller lain, service laporan, halaman frontend, tipe di `types.ts`, test).
- Perubahan bentuk respons API wajib dicek ke sisi frontend.

## Tahap 04 — IMPACT ANALYSIS
- Identifikasi fitur/role yang terdampak, termasuk efek tidak langsung: saldo kas, laporan bulanan/kategori, saldo Kas Sekretariat, status biaya rutin.
- Area saldo, transaksi, laporan, dan import data diperlakukan **berisiko tinggi** secara default.

## Tahap 05 — DATABASE ANALYSIS
- Cek skema, relasi, constraint, migrasi yang ada.
- Migrasi baru: perhatikan data yang sudah ada (nullable/default, backfill). Jangan menghapus data tanpa konfirmasi eksplisit.
- Jangan menjalankan migration destruktif (drop column/table, truncate, `migrate:fresh` di DB berisi data asli) tanpa persetujuan eksplisit di tahap 08.

## Tahap 06 — BUG/REGRESSION ANALYSIS
- Cek riwayat bug/TODO/FIXME pada area terkait.
- Edge case: nominal nol/desimal, transaksi lintas bulan, transaksi terhapus (soft delete), LPJ yang masuk setelah bulan berganti, kategori dua arah.

## Tahap 07 — IMPLEMENTATION PLAN
- Daftar file yang diubah, jenis perubahan, urutan. Proporsional dengan scope tahap 00.

## Tahap 08 — HUMAN APPROVAL (hard stop)
- Masuk **Plan Mode** dan sajikan rencana lewat exit-plan-mode.
- **Tidak boleh mulai tahap 09 sebelum user menyetujui secara eksplisit.**
- Jika user minta perubahan, ulangi dari bagian yang relevan sebelum minta approval lagi.

## Tahap 09 — IMPLEMENTATION
- Eksekusi sesuai rencana yang disetujui. Tidak ada scope creep tanpa konfirmasi.

## Tahap 10 — VERIFICATION
- `php artisan test` untuk backend; `npm run build` + `npm run lint` untuk frontend.
- Perubahan UI diuji langsung di browser bila memungkinkan.

## Tahap 11 — REGRESSION TEST
- Uji ulang fitur yang teridentifikasi terdampak di tahap 03/04, bukan cuma kode yang baru diubah.

## Tahap 12 — FINAL REVIEW
- Tinjau diff: tanpa kode debug/dead code, tanpa kredensial/secret, sesuai konvensi.
- Cek keamanan dasar: validasi input, otorisasi role, akses file lampiran, data pribadi pemberi persembahan.

## Tahap 13 — CHANGE REPORT
- Ringkas ke user: apa yang berubah, kenapa, file mana, apa yang sudah diuji, dan risiko yang tersisa.
- Jangan buat file laporan terpisah kecuali diminta.
