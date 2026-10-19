#!/bin/sh
# Dijalankan oleh Cron Job hPanel (mis. tiap 5 menit):
#   sh /home/USER/domains/cattleyaapp.com/public_html/gkt/post-deploy.sh
#
# Hanya bekerja jika ada deploy baru (isi backend/DEPLOYED_COMMIT berubah):
# migrate database + cache konfigurasi. Selain itu langsung keluar.
# Paksa jalan ulang (mis. setelah mengubah .env): hapus backend/storage/app/.last_deployed
set -eu

ROOT="$(cd "$(dirname "$0")" && pwd)"
APP="$ROOT/backend"
LOG="$APP/storage/logs/deploy.log"
LOCK="$APP/storage/app/.deploy.lock"

# PHP 8.3: pakai PHP_BIN jika diset, lalu versi 8.3 Hostinger, terakhir "php" bawaan.
if [ -z "${PHP_BIN:-}" ]; then
    if [ -x /opt/alt/php83/usr/bin/php ]; then PHP_BIN=/opt/alt/php83/usr/bin/php; else PHP_BIN=php; fi
fi

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

CURRENT="$(cat "$APP/DEPLOYED_COMMIT" 2>/dev/null || echo none)"
LAST="$(cat "$APP/storage/app/.last_deployed" 2>/dev/null || echo '')"
[ "$CURRENT" = "$LAST" ] && exit 0

# Cegah dua cron berjalan bersamaan.
mkdir "$LOCK" 2>/dev/null || exit 0
trap 'rmdir "$LOCK"' EXIT

if [ ! -f "$APP/.env" ]; then
    log "GAGAL: backend/.env belum ada. Buat dari backend/.env.production.example."
    exit 1
fi

cd "$APP"
log "Deploy $CURRENT dimulai ($($PHP_BIN -r 'echo PHP_VERSION;'))"
{
    $PHP_BIN artisan migrate --no-ansi --force --no-interaction
    $PHP_BIN artisan optimize:clear --no-ansi
    $PHP_BIN artisan config:cache --no-ansi
    $PHP_BIN artisan route:cache --no-ansi
    [ -e public/storage ] || $PHP_BIN artisan storage:link --no-ansi || true
} >> "$LOG" 2>&1 || { log "GAGAL: lihat pesan di atas."; exit 1; }

echo "$CURRENT" > storage/app/.last_deployed
log "Deploy $CURRENT selesai."
