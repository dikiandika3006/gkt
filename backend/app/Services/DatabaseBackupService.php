<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

/**
 * Backup & restore seluruh database MySQL lewat PHP murni (PDO), tanpa mysqldump/mysql CLI,
 * supaya tetap jalan di shared hosting yang mematikan exec/proc_open.
 *
 * Format file: SQL biasa (gzip), satu statement diakhiri ";" di akhir baris. String tidak pernah
 * berisi baris baru mentah (di-escape), sehingga restore cukup membaca per baris. Format yang sama
 * dipakai mysqldump & export phpMyAdmin, jadi file dari keduanya juga bisa di-restore.
 */
class DatabaseBackupService
{
    /** Tabel data sementara: hanya strukturnya yang dibackup. */
    private const STRUCTURE_ONLY = ['sessions', 'cache', 'cache_locks'];

    private const NAME_PATTERN = '/^backup_\d{8}_\d{6}(_pre-restore)?\.sql(\.gz)?$/';

    private const MAX_INSERT_BYTES = 1024 * 1024;

    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'];

    public function dir(): string
    {
        return storage_path('app/private/'.trim(config('backup.path', 'backups'), '/'));
    }

    /** Daftar file backup, terbaru di atas: [name, size, time]. */
    public function list(): array
    {
        if (! is_dir($this->dir())) {
            return [];
        }

        return collect(File::files($this->dir()))
            ->filter(fn ($f) => preg_match(self::NAME_PATTERN, $f->getFilename()))
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'time' => $f->getMTime()])
            ->sortByDesc('name')
            ->values()
            ->all();
    }

    /** Path absolut file backup; nama divalidasi ketat (anti path traversal). */
    public function path(string $name): string
    {
        $path = $this->dir().DIRECTORY_SEPARATOR.$name;
        if (! preg_match(self::NAME_PATTERN, $name) || ! is_file($path)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }

        return $path;
    }

    public function delete(string $name): void
    {
        File::delete($this->path($name));
    }

    /** Ukuran data + index database saat ini (byte). */
    public function databaseSize(): int
    {
        // Hanya MySQL (aplikasi); di test SQLite cukup 0.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return 0;
        }

        return (int) DB::selectOne(
            'SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->size;
    }

    /**
     * Jalankan $fn dengan kunci file: backup/restore tidak boleh berjalan bersamaan.
     * Pakai file (bukan cache), karena tabel cache ikut ditimpa saat restore.
     */
    public function exclusive(callable $fn)
    {
        File::ensureDirectoryExists($this->dir());
        $lock = fopen($this->dir().DIRECTORY_SEPARATOR.'.lock', 'c');

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Proses backup/restore lain sedang berjalan. Coba lagi beberapa saat lagi.');
        }

        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Buat file backup baru, kembalikan namanya. Semua tabel dibaca dalam satu snapshot
     * transaksi (konsisten tanpa mengunci tabel).
     */
    public function backup(string $suffix = ''): string
    {
        $this->longRunning();
        File::ensureDirectoryExists($this->dir());

        $name = 'backup_'.date('Ymd_His').$suffix.'.sql.gz';
        while (is_file($this->dir().DIRECTORY_SEPARATOR.$name)) {
            sleep(1);
            $name = 'backup_'.date('Ymd_His').$suffix.'.sql.gz';
        }
        $final = $this->dir().DIRECTORY_SEPARATOR.$name;
        $tmp = $final.'.part';

        $pdo = DB::connection()->getPdo();
        $gz = gzopen($tmp, 'wb6');

        try {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            gzwrite($gz, "-- Keuangan GKT Sinai database backup\n"
                .'-- Dibuat: '.date('Y-m-d H:i:s')."\n"
                .'-- Database: '.DB::connection()->getDatabaseName()."\n\n"
                ."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($this->tables($pdo) as $table) {
                $create = $pdo->query('SHOW CREATE TABLE '.$this->ident($table))->fetch(PDO::FETCH_NUM)[1];
                gzwrite($gz, "DROP TABLE IF EXISTS {$this->ident($table)};\n{$create};\n\n");

                if (! in_array($table, self::STRUCTURE_ONLY, true)) {
                    $this->dumpRows($pdo, $gz, $table);
                }
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            gzclose($gz);
            @unlink($tmp);
            throw $e;
        }

        gzclose($gz);
        rename($tmp, $final);

        return $name;
    }

    /**
     * Pastikan file adalah backup database aplikasi ini (berisi tabel users & migrations).
     */
    public function validate(string $path): void
    {
        $found = [];
        foreach ($this->lines($path) as $line) {
            if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`?(users|migrations)`?\s/i', $line, $m)) {
                $found[strtolower($m[1])] = true;
                if (count($found) === 2) {
                    return;
                }
            }
        }

        throw new RuntimeException('File bukan backup database aplikasi ini (tabel users/migrations tidak ditemukan).');
    }

    /**
     * Timpa database dengan isi file .sql / .sql.gz. Kembalikan jumlah statement yang dijalankan.
     * Perintah yang mengganti database (USE / CREATE / DROP DATABASE) dilewati.
     */
    public function restore(string $path): int
    {
        $this->longRunning();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $count = 0;
        $statement = '';

        try {
            foreach ($this->lines($path) as $line) {
                $trim = trim($line);
                if ($statement === '' && ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#'))) {
                    continue;
                }

                $statement .= $line;
                if (! str_ends_with($trim, ';')) {
                    continue;
                }

                $sql = trim($statement);
                $statement = '';

                if (preg_match('/^(USE\s|CREATE\s+DATABASE\s|DROP\s+DATABASE\s)/i', $sql)
                    || preg_match('/^\/\*[^!].*\*\/\s*;$/s', $sql)) {
                    continue;
                }

                $pdo->exec($sql);
                $count++;
            }

            if (trim($statement) !== '') {
                throw new RuntimeException('File backup terpotong: statement terakhir tidak lengkap.');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        return $count;
    }

    private function tables(PDO $pdo): array
    {
        return array_map(
            fn ($row) => $row[0],
            array_filter($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM), fn ($row) => $row[1] === 'BASE TABLE')
        );
    }

    /** Tulis isi tabel sebagai INSERT multi-baris (maks ±1 MB per statement). */
    private function dumpRows(PDO $pdo, $gz, string $table): void
    {
        $binary = DB::table('information_schema.columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->whereIn('data_type', self::BINARY_TYPES)
            ->pluck('column_name')
            ->all();

        // Unbuffered: baris dibaca bertahap dari server, memori tetap kecil untuk tabel besar.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $stmt = $pdo->query('SELECT * FROM '.$this->ident($table));
            $prefix = null;
            $values = [];
            $bytes = 0;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $prefix ??= 'INSERT INTO '.$this->ident($table)
                    .' ('.implode(', ', array_map([$this, 'ident'], array_keys($row))).') VALUES ';

                $cells = [];
                foreach ($row as $col => $value) {
                    $cells[] = match (true) {
                        $value === null => 'NULL',
                        in_array($col, $binary, true) => $value === '' ? "''" : '0x'.bin2hex($value),
                        default => $pdo->quote((string) $value),
                    };
                }

                $tuple = '('.implode(',', $cells).')';
                $values[] = $tuple;
                $bytes += strlen($tuple);

                if ($bytes >= self::MAX_INSERT_BYTES) {
                    gzwrite($gz, $prefix.implode(",\n", $values).";\n");
                    $values = [];
                    $bytes = 0;
                }
            }

            if ($values) {
                gzwrite($gz, $prefix.implode(",\n", $values).";\n");
            }
            $stmt->closeCursor();
            gzwrite($gz, "\n");
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /** Baca file per baris; gzopen juga bisa membaca file .sql yang tidak terkompresi. */
    private function lines(string $path): \Generator
    {
        $fh = gzopen($path, 'rb');
        if (! $fh) {
            throw new RuntimeException('File backup tidak bisa dibuka.');
        }

        try {
            while (($line = gzgets($fh)) !== false) {
                yield $line;
            }
        } finally {
            gzclose($fh);
        }
    }

    private function ident(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function longRunning(): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
    }
}
