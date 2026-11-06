/**
 * Konten halaman Setting → About. Diedit manual setiap ada perubahan aplikasi:
 * RULES mendokumentasikan aturan yang berlaku; CHANGELOG adalah riwayat perubahan, terbaru di paling atas.
 */

export const APP_NAME = 'Keuangan GKT Sinai'

export interface ChangelogEntry {
  /** yyyy-MM-dd */
  date: string
  title: string
  changes: string[]
}

export const CHANGELOG: ChangelogEntry[] = [
  {
    date: '2026-11-09',
    title: 'Menu Laporan baru & 10 laporan tambahan',
    changes: [
      'Laporan kini menjadi grup menu sendiri (bisa dilipat), dikelompokkan Bulanan, Dana, Analisa, Tahunan — satu laporan satu halaman.',
      'Laporan Bulanan siap cetak dengan kop & tanda tangan; nama gereja dan penandatangan diatur di Setting → Laporan Cetak.',
      'Tutup Buku: bulan yang sudah ditutup tidak bisa ditambah/diubah/dihapus transaksinya; ditutup admin/bendahara, dibuka kembali hanya admin.',
      'Rekonsiliasi Bank: cocokkan saldo aplikasi dengan rekening koran / hitung fisik kas tiap akhir bulan.',
      'Posisi Dana (dana bebas vs dana khusus, titipan, pinjaman) dan kartu Titipan & Pinjaman per nama.',
      'Realisasi Anggaran per bulan & kumulatif, Perbandingan Tahunan, dan Tren Persembahan mingguan.',
      'Rekap per Pemberi (data pribadi): modul Hak Akses baru, default hanya admin.',
      'LPJ Tahunan untuk Rapat Jemaat: dokumen cetak + Excel 6 sheet.',
    ],
  },
  {
    date: '2026-11-07',
    title: 'Backup & Restore database',
    changes: [
      'Form rahasia di About (Alt+F7, khusus admin, wajib PIN): buat & download backup database, riwayat backup, dan restore.',
      'Restore dari riwayat atau upload file .sql/.sql.gz; backup pengaman "_pre-restore" dibuat otomatis sebelum restore.',
      'Hanya database; lampiran (foto nota, bukti transfer) tidak ikut.',
    ],
  },
  {
    date: '2026-11-07',
    title: 'Mutasi per kas & Akses Kas',
    changes: [
      'Halaman Mutasi: Kas / Rekening dipilih di bagian atas dan wajib (tidak ada lagi "Semua kas"); filter lain dirapikan menjadi satu baris.',
      'Hak Akses → Akses Kas di Mutasi: atur kas yang boleh dilihat & dipakai tiap role (mis. Sekretariat hanya Kas Sekretariat).',
      'Setting → Mutasi: kas default (mis. Rekening Utama) dan kas default bila tidak punya akses (mis. Kas Sekretariat).',
      'Transaksi baru otomatis memakai kas yang sedang dipilih.',
    ],
  },
  {
    date: '2026-11-06',
    title: 'Menu Setting & About',
    changes: [
      'Grup menu baru Setting (khusus admin) berisi Setting dan About.',
      'Setting "Rekening di Dashboard": pilih Semua kas & bank atau satu kas/bank; Total Saldo dan Saldo per Kas di Dashboard mengikuti pilihan ini.',
      'Perubahan setting tercatat di Log Aktivitas.',
      'Halaman About: info aplikasi, aturan sistem, dan riwayat perubahan.',
    ],
  },
  {
    date: '2026-11-06',
    title: 'Kas & Bank: saldo saat ini',
    changes: ['Daftar Kas & Bank menampilkan saldo saat ini; saldo awal hanya tampil di form.'],
  },
  {
    date: '2026-11-04',
    title: 'Nota LPJ tanpa kategori',
    changes: [
      'Sekretariat boleh menyimpan nota LPJ tanpa kategori; nota masuk kategori sementara "Belum Dikategorikan" sehingga saldo kas tetap langsung benar.',
      'Nota ditandai "Belum dikategorikan" beserta jumlahnya di kartu LPJ; bendahara memilih kategorinya, transaksi ikut diperbarui.',
    ],
  },
  {
    date: '2026-11-04',
    title: 'Kas Sekretariat: bukti transfer & foto lampiran',
    changes: [
      'Tiap transfer pengajuan dana bisa diberi lampiran bukti transfer.',
      'Lampiran menerima semua format foto HP (termasuk HEIC); foto otomatis diperkecil sebelum diunggah.',
    ],
  },
  {
    date: '2026-10-29',
    title: 'Hak Akses, Rekap Persembahan & Pos Persembahan',
    changes: [
      'Menu Hak Akses (admin): Tidak ada / Lihat / Kelola per role × modul; Administrator selalu penuh.',
      'Default role Sekretariat: hanya Kas Sekretariat dan Laporan Sekretariat, dengan dashboard khusus Kas Sekretariat.',
      'Rekap persembahan mingguan (lembar warta "Laporan Persembahan Mingguan") dengan crosscheck ke transaksi; rentang crosscheck default = periode persembahan.',
      'Master Pos Persembahan: jadwal Minggu ke-N, rekening pos, dan daftar kebaktian.',
    ],
  },
  {
    date: '2026-10-25',
    title: 'Insight lebih ringkas',
    changes: ['Rincian nominal pada Insight dilipat dan tampil lewat "Tampilkan detail".'],
  },
  {
    date: '2026-10-19',
    title: 'Insight, Anggaran, Tanya Keuangan & Dashboard baru',
    changes: [
      'Insight keuangan otomatis: pengeluaran terbesar, lonjakan, tren pemasukan, dana cadangan, biaya rutin, over budget.',
      'Modul Anggaran tahunan per kategori beserta realisasi; bisa disalin dari tahun lalu.',
      'Asisten "Tanya Keuangan": tanya-jawab dari data keuangan tanpa AI luar.',
      'Tampilan Dashboard baru; Biaya Rutin diisi dari sheet "Biaya Pasti Bulanan".',
      'Import mutasi bank: saran kategori mendahulukan biaya rutin.',
    ],
  },
  {
    date: '2026-10-19',
    title: 'Rilis awal aplikasi',
    changes: [
      'Mutasi kas (pemasukan/pengeluaran), mutasi antar kas, Kas Sekretariat (pengajuan, transfer, LPJ).',
      'Biaya rutin, laporan + export Excel, import Data Mutasi Excel lama & mutasi bank (CSV).',
      'Kode kategori otomatis; menu "Transaksi Kas" diganti "Mutasi".',
      'Layar tidak lagi kosong setelah ada versi baru (muat ulang otomatis).',
    ],
  },
]

export const RULES: Record<string, string[]> = {
  'Kas & Bank': [
    'Setiap kas (Rekening Gereja, Kas Sekretariat, dst.) punya saldo awal; saldo = saldo awal + seluruh mutasi.',
    'Kas yang sudah dipakai transaksi tidak bisa dihapus, hanya dinonaktifkan.',
  ],
  'Kategori': [
    'Kategori punya kelompok (untuk laporan), jenis (pemasukan / pengeluaran / dua arah), dan sifat.',
    'Sifat Operasional dihitung di surplus/defisit. Titipan, Pinjaman, dan Mutasi antar kas tidak dihitung sebagai pemasukan/pengeluaran operasional.',
    'Kode kategori dibuat otomatis dan terkunci setelah disimpan.',
  ],
  'Mutasi & Mutasi antar Kas': [
    'Halaman Mutasi selalu menampilkan satu kas yang dipilih. Kas awal = kas default di Setting bila boleh diakses, jika tidak kas cadangan, jika tidak kas pertama yang boleh.',
    'Setiap role hanya bisa melihat, mencatat, mengubah, menghapus, mengimpor, dan mengekspor transaksi pada kas yang diizinkan di Hak Akses → Akses Kas. Mutasi antar kas butuh akses ke kedua kas.',
    'Mutasi antar kas tercatat sebagai dua transaksi berpasangan (keluar dari kas asal, masuk ke kas tujuan) yang diubah/dihapus bersamaan.',
    'Import mutasi bank (CSV) dan Data Mutasi Excel lama diperiksa dulu sebelum disimpan; kategori disarankan dari riwayat, biaya rutin, lalu kata kunci.',
  ],
  'Kas Sekretariat': [
    'Alur: Pengajuan dana → transfer dari rekening (mutasi antar kas) → LPJ per nota (pengeluaran dari Kas Sekretariat). Tanpa approval.',
    'Sisa saldo Kas Sekretariat dibawa ke pengajuan berikutnya.',
    'Nota tanpa kategori masuk "Belum Dikategorikan" sampai diisi bendahara.',
  ],
  'Biaya Rutin & Anggaran': [
    'Biaya rutin hanya anggaran bulanan; pembayaran nyata dicatat sebagai transaksi biasa yang ditautkan ke item dan periode.',
    'Anggaran disusun per tahun per kategori dan dibandingkan dengan realisasi transaksi.',
  ],
  'Rekap Persembahan': [
    'Rekap persembahan dibuat per minggu dan bisa dicocokkan (crosscheck) dengan transaksi pada periode persembahan.',
    'Pos persembahan khusus mengikuti jadwal Minggu ke-N di menu Pos Persembahan.',
  ],
  'Laporan & Tutup Buku': [
    'Laporan "Semua kas" = kas umum; rekening ber-pocket (dana khusus) dilaporkan terpisah per pocket.',
    'Bulan yang sudah ditutup buku tidak bisa ditambah/diubah/dihapus transaksinya lewat jalur mana pun (form, mutasi antar kas, import, LPJ, rekap persembahan). Menutup: admin/bendahara; membuka kembali: admin.',
    'Rekonsiliasi diisi per kas per akhir bulan; selisih ≠ 0 berarti ada transaksi yang belum/salah dicatat.',
    'Dana bebas = total uang − dana khusus − titipan belum disalurkan − utang pinjaman.',
    'Rekap per Pemberi berisi data pribadi; hanya untuk role yang diberi hak "Rekap per Pemberi" (default admin).',
  ],
  'Dashboard': [
    'Total Saldo dan Saldo per Kas mengikuti Setting "Rekening di Dashboard" (Semua atau satu kas/bank).',
    'Pemasukan, pengeluaran, grafik, Ketahanan Kas, dan Biaya Rutin selalu dihitung dari semua kas.',
  ],
  'Hak Akses & Audit': [
    'Role: Administrator, Bendahara, Sekretariat, Viewer. Hak tiap role per modul diatur di menu Hak Akses; Administrator selalu penuh.',
    'Akses kas di Mutasi diatur per role; tanpa pengaturan = semua kas. Administrator selalu semua kas.',
    'Pengguna, Log Aktivitas, Hak Akses, Setting, dan About khusus Administrator.',
    'Setiap tambah/ubah/hapus data keuangan dan setting tercatat di Log Aktivitas.',
  ],
}
