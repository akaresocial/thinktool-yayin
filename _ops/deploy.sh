#!/bin/bash
# thinktool.com.tr — sunucu tarafı otomatik yayın (Alastyr cPanel, cron ile 2 dakikada bir).
#
# Akış: açık yayın deposunun (akaresocial/thinktool-yayin) main dalındaki son commit'i kontrol et → yeni sürüm varsa indir,
# doğrula → veritabanı şemasını güncelle (ilk kurulumda içeriği yükle) → web kökünün anlık yedeğini al → yer değiştirerek
# kur → canlı siteyi test et → test başarısızsa önceki sürüme geri dön.
#
# Hiçbir şifre/anahtar kullanmaz: depo herkese açıktır ve yalnızca derlenmiş siteyi içerir. Kalıcı veriler (veritabanı,
# siparişler, görseller, PayTR bilgileri) web kökü DIŞINDA, ~/thinktool-data altındadır ve bu betik onlara dokunmaz
# (yalnızca veritabanı şemasını günceller ve yedek alır).
#
# Yalnızca BU betiğin kurduğu girdiler (installed.txt) ve yeni sürümdekiler değiştirilir; web kökündeki diğer her şey
# (başka klasörler, .well-known, doğrulama dosyaları, uploads bağlantısı) yerinde kalır.
# WordPress bulunan bir web köküne (ilk geçiş) yalnızca ALLOW_WP_REPLACE=1 verilirse kurulur; önce tam yedek alınır.
#
# Uygulamanın zamanlanmış görevleri (günlük kur 10:00, veritabanı yedeği 03:00, temizlik) de buradan 10 dakikada bir çalışır.
#
# Kullanım (cPanel → Cron İşleri, tek satır):
#   */2 * * * * SITE_URL=https://thinktool.com.tr WEBROOT=$HOME/public_html bash $HOME/thinktool-ops/deploy.sh >> $HOME/thinktool-ops/deploy.log 2>&1
set -u
umask 022

REPO="${REPO:-akaresocial/thinktool-yayin}"
BRANCH="${BRANCH:-main}"
SITE_URL="${SITE_URL:-https://thinktool.com.tr}"
WEBROOT="${WEBROOT:-$HOME/public_html}"
OPS="${OPS:-$HOME/thinktool-ops}"
DATA="${DATA:-$HOME/thinktool-data}"
BACKUPS="${BACKUPS:-$HOME/yedekler}"
KEEP=3

mkdir -p "$OPS/releases" "$OPS/snapshots" "$BACKUPS"
LOG_MAX=1048576
[ -f "$OPS/deploy.log" ] && [ "$(wc -c < "$OPS/deploy.log")" -gt "$LOG_MAX" ] && tail -c 262144 "$OPS/deploy.log" > "$OPS/deploy.log.tmp" && mv "$OPS/deploy.log.tmp" "$OPS/deploy.log"

ts() { date '+%Y-%m-%dT%H:%M:%S%z'; }
log() { echo "[$(ts)] $*"; }
status() { printf '{"time":"%s","state":"%s","sha":"%s","note":"%s","site":"%s"}\n' "$(ts)" "$1" "${2:-}" "${3:-}" "$SITE_URL" > "$OPS/status.json"; }

# ---------------------------------------------------------------- tek örnek
exec 9> "$OPS/.lock"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || exit 0
else
  if ! mkdir "$OPS/.lockdir" 2>/dev/null; then
    if [ -n "$(find "$OPS/.lockdir" -maxdepth 0 -mmin +20 2>/dev/null)" ]; then rmdir "$OPS/.lockdir"; mkdir "$OPS/.lockdir" || exit 0; else exit 0; fi
  fi
  trap 'rmdir "$OPS/.lockdir" 2>/dev/null' EXIT
fi

# ---------------------------------------------------------------- PHP (komut satırı) — en az 8.2 + pdo_sqlite
if [ -z "${PHP:-}" ]; then
  for c in /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php83/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/alt/php84/usr/bin/php /usr/local/bin/php php; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; then PHP="$c"; break; fi
  done
fi
if [ -z "${PHP:-}" ]; then log "php: HATA — PHP 8.2+ (pdo_sqlite) bulunamadı"; status "no-php"; exit 1; fi

# ---------------------------------------------------------------- araç kontrolü (ilk çalışmada günlüğe yazılır)
if [ ! -f "$OPS/.doctor" ]; then
  log "doctor: bash=$BASH_VERSION php=$PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
  for t in curl tar gzip git flock sha256sum find cp; do
    if command -v "$t" >/dev/null 2>&1; then log "doctor: $t=$(command -v "$t")"; else log "doctor: $t=YOK"; fi
  done
  log "doctor: disk=$(du -sh "$HOME" 2>/dev/null | cut -f1) webroot=$WEBROOT"
  touch "$OPS/.doctor"
fi

# ---------------------------------------------------------------- uygulama görevleri (10 dakikada bir: kur 10:00, yedek 03:00, temizlik)
if [ -f "$WEBROOT/_app/cli.php" ] && [ -z "$(find "$OPS/.app-cron" -mmin -9 2>/dev/null)" ]; then
  touch "$OPS/.app-cron"
  mkdir -p "$DATA/logs"
  (cd "$WEBROOT" && TT_DATA_DIR="$DATA" timeout 300 "$PHP" _app/cli.php cron >> "$DATA/logs/cron.log" 2>&1) || log "uygulama görevleri: HATA (ayrıntı: $DATA/logs/cron.log)"
fi

# ---------------------------------------------------------------- uzak sürüm
remote_sha="${TEST_SHA:-}"
if [ -z "$remote_sha" ] && command -v git >/dev/null 2>&1; then
  remote_sha=$(GIT_TERMINAL_PROMPT=0 timeout 30 git -c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 ls-remote "https://github.com/$REPO.git" "refs/heads/$BRANCH" 2>/dev/null | cut -f1)
fi
if [ -z "$remote_sha" ]; then
  remote_sha=$(curl -fsS --max-time 20 "https://api.github.com/repos/$REPO/commits/$BRANCH" -H 'Accept: application/vnd.github.sha' 2>/dev/null | tr -dc '0-9a-f' | head -c 40)
fi
if [ ${#remote_sha} -ne 40 ]; then
  log "uzak: sürüm okunamadı (ağ/GitHub); sonraki çalışmada tekrar"
  exit 0
fi

current_sha=$(cat "$OPS/current_sha" 2>/dev/null || true)
[ "$remote_sha" = "$current_sha" ] && exit 0
if grep -qx "$remote_sha" "$OPS/bad_shas" 2>/dev/null; then exit 0; fi

log "yeni sürüm: $remote_sha (canlı: ${current_sha:-yok})"
rel="$OPS/releases/$remote_sha"
if [ ! -d "$rel/public" ]; then
  rm -rf "$rel.tmp" && mkdir -p "$rel.tmp"
  if ! curl -fsSL --connect-timeout 20 --max-time 300 "https://codeload.github.com/$REPO/tar.gz/$remote_sha" | tar -xz -C "$rel.tmp" --strip-components=1; then
    log "indirme: HATA"; rm -rf "$rel.tmp"; exit 0
  fi
  mv "$rel.tmp" "$rel"
fi

# ---------------------------------------------------------------- doğrulama
fail() { log "doğrulama: HATA — $*"; echo "$remote_sha" >> "$OPS/bad_shas"; status "invalid" "$remote_sha" "$*"; exit 1; }
for f in index.html .htaccess 404.html sitemap.xml version.txt api/version.php _app/bootstrap.php _app/cli.php yonetim/index.php; do
  [ -f "$rel/public/$f" ] || fail "$f yok"
done
rid=$(tr -dc '0-9a-f' < "$rel/_ops/release-id" 2>/dev/null)
[ ${#rid} -ge 8 ] || fail "_ops/release-id yok"
grep -q "^$rid" "$rel/public/version.txt" 2>/dev/null || fail "version.txt yayın kimliğiyle uyuşmuyor"
nfiles=$(find "$rel/public" -type f | wc -l)
[ "$nfiles" -ge 100 ] || fail "dosya sayısı çok az ($nfiles)"
# Betikler yalnızca api/, yonetim/, _app/ altında olabilir.
bad_exec=$(cd "$rel/public" && find . -type f \( -iname '*.php' -o -iname '*.php[0-9]' -o -iname '*.phtml' -o -iname '*.phar' -o -iname '*.cgi' -o -iname '*.pl' -o -iname '*.py' -o -iname '*.sh' \) \
  | grep -vE '^\./(api|yonetim|_app)/' | head -3)
[ -z "$bad_exec" ] || fail "izin verilmeyen yerde betik: $bad_exec"
[ -z "$(cd "$rel/public" && find . -type l | head -1)" ] || fail "pakette sembolik bağlantı var"
if grep -RIEiq '^[[:space:]]*(AddHandler|SetHandler|Action|ScriptAlias|php_value|php_flag|php_admin|AddType[^#]*php)' --include=.htaccess "$rel/public"; then
  fail ".htaccess içinde betik çalıştırma yönergesi var"
fi
[ -f "$rel/_ops/SHA256SUMS" ] || fail "SHA256SUMS yok"
if command -v sha256sum >/dev/null 2>&1; then
  (cd "$rel/public" && sha256sum --quiet -c "../_ops/SHA256SUMS") >/dev/null 2>&1 || fail "sağlama toplamı uyuşmuyor"
  listed=$(grep -c . "$rel/_ops/SHA256SUMS")
  [ "$listed" -eq "$nfiles" ] || fail "SHA256SUMS dosya sayısı ($listed) ile paket ($nfiles) uyuşmuyor"
fi

# Betik kendini depodan GÜNCELLEMEZ (depoya yazabilen biri sunucuda kod çalıştıramasın diye). Farklıysa yalnızca günlüğe yazılır.
if [ -f "$rel/_ops/deploy.sh" ] && ! cmp -s "$rel/_ops/deploy.sh" "$OPS/deploy.sh" 2>/dev/null; then
  log "betik: depodaki deploy.sh farklı — otomatik güncellenmedi"
fi

# güvenlik kapısı
if [ "$(tr -dc '0-9' < "$rel/_ops/enabled" 2>/dev/null)" != "1" ]; then
  log "kapı: _ops/enabled != 1 — kurulum yapılmadı (yalnızca indirildi ve doğrulandı)"
  status "gated" "$remote_sha"
  exit 0
fi

# ---------------------------------------------------------------- WordPress koruması (ilk geçiş)
mkdir -p "$WEBROOT"
if [ -f "$WEBROOT/wp-config.php" ]; then
  if [ "${ALLOW_WP_REPLACE:-0}" != "1" ]; then
    log "kapı: $WEBROOT içinde WordPress var ve ALLOW_WP_REPLACE=1 verilmedi — kurulum yapılmadı"
    status "wordpress-present" "$remote_sha"
    exit 0
  fi
  if [ ! -f "$BACKUPS/.wp-ok" ]; then
    stamp=$(date '+%Y%m%d-%H%M%S')
    log "yedek: WordPress dosyaları arşivleniyor → $BACKUPS/wp-public_html-$stamp.tar.gz"
    if ! tar -czf "$BACKUPS/wp-public_html-$stamp.tar.gz" -C "$(dirname "$WEBROOT")" "$(basename "$WEBROOT")"; then
      log "yedek: HATA — arşiv oluşturulamadı; kurulum yapılmayacak"; status "backup-failed" "$remote_sha"; exit 1
    fi
    wpc="$WEBROOT/wp-config.php"
    getv() { sed -n "s/^[[:space:]]*define([[:space:]]*['\"]$1['\"][[:space:]]*,[[:space:]]*['\"]\(.*\)['\"][[:space:]]*);.*/\1/p" "$wpc" | head -1; }
    DBN=$(getv DB_NAME); DBU=$(getv DB_USER); DBP=$(getv DB_PASSWORD); DBH=$(getv DB_HOST)
    if [ -n "$DBN" ] && command -v mysqldump >/dev/null 2>&1; then
      if MYSQL_PWD="$DBP" mysqldump -h "${DBH:-localhost}" -u "$DBU" --single-transaction --no-tablespaces "$DBN" | gzip > "$BACKUPS/wp-db-$stamp.sql.gz"; then
        log "yedek: WordPress veritabanı → $BACKUPS/wp-db-$stamp.sql.gz"
      else
        log "yedek: UYARI — veritabanı dökümü başarısız (veritabanına zaten dokunulmuyor)"
      fi
    fi
    touch "$BACKUPS/.wp-ok"
    log "yedek: tamam ($(du -h "$BACKUPS/wp-public_html-$stamp.tar.gz" | cut -f1))"
  fi
fi

# ---------------------------------------------------------------- veri klasörü + veritabanı (şema, ilk içerik)
mkdir -p "$DATA/uploads" && chmod 711 "$DATA" && chmod 755 "$DATA/uploads"
seed_arg=""
has_products=$("$PHP" -r '$f=$argv[1]; if(!is_file($f)){echo 0; exit;} try{$p=new PDO("sqlite:$f"); echo (int)$p->query("SELECT COUNT(*) FROM products")->fetchColumn();}catch(Throwable $e){echo 0;}' "$DATA/thinktool.sqlite" 2>/dev/null)
if [ "${has_products:-0}" = "0" ]; then
  seed="$OPS/seed"
  if [ ! -f "$seed/snapshot.json" ]; then
    log "ilk kurulum: başlangıç içeriği indiriliyor (seed dalı)"
    rm -rf "$seed.tmp" && mkdir -p "$seed.tmp"
    if curl -fsSL --connect-timeout 20 --max-time 300 "https://codeload.github.com/$REPO/tar.gz/refs/heads/seed" | tar -xz -C "$seed.tmp" --strip-components=1 \
      && (cd "$seed.tmp" && sha256sum --quiet -c SHA256SUMS >/dev/null 2>&1); then
      mv "$seed.tmp" "$seed"
    else
      rm -rf "$seed.tmp"; log "ilk kurulum: HATA — başlangıç içeriği indirilemedi/doğrulanamadı"; status "seed-failed" "$remote_sha"; exit 1
    fi
  fi
  # Görseller: var olan dosyaların üzerine yazılmaz
  (cd "$seed/uploads" && find . -type f | while IFS= read -r f; do [ -e "$DATA/uploads/$f" ] || cp "$f" "$DATA/uploads/$f"; done)
  find "$DATA/uploads" -type f -exec chmod 644 {} +
  seed_arg="--seed=$seed/snapshot.json"
fi
if ! out=$(cd "$rel/public" && TT_DATA_DIR="$DATA" "$PHP" _app/cli.php install $seed_arg --url="$SITE_URL" --ops="$OPS" 2>&1); then
  log "veritabanı: HATA — $out"; echo "$remote_sha" >> "$OPS/bad_shas"; status "db-failed" "$remote_sha"; exit 1
fi
while IFS= read -r l; do [ -n "$l" ] && log "uygulama: $l"; done <<< "$out"

# ---------------------------------------------------------------- hazırlık
stage="$OPS/stage-$rid"
rm -rf "$stage" && mkdir -p "$stage"
cp -a "$rel/public/." "$stage/" || { log "hazırlık: HATA"; status "stage-failed" "$remote_sha"; exit 1; }
# cPanel'in yazdığı bloklar (MultiPHP: PHP sürümü ve php.ini ayarları) eski .htaccess'ten korunur
if [ -f "$WEBROOT/.htaccess" ] && grep -q 'BEGIN cPanel-generated' "$WEBROOT/.htaccess"; then
  { sed -n '/BEGIN cPanel-generated/,/END cPanel-generated/p' "$WEBROOT/.htaccess"; echo; cat "$stage/.htaccess"; } > "$stage/.htaccess.new" \
    && mv "$stage/.htaccess.new" "$stage/.htaccess"
fi
grep -q 'cPanel-generated handler' "$stage/.htaccess" || log "uyarı: .htaccess'te cPanel PHP sürümü satırı yok — alan adının PHP sürümü MultiPHP'den 8.3 olmalı"

# Korunan girdiler: asla taşınmaz/silinmez
PRESERVE=" .well-known cgi-bin .user.ini php.ini uploads wp-content error_log "
is_preserved() {
  case "$PRESERVE" in *" $1 "*) return 0 ;; esac
  [[ "$1" =~ ^(google[0-9a-f]+\.html|yandex_[0-9a-f]+\.html|BingSiteAuth\.xml)$ ]] && return 0
  return 1
}
# Değiştirilecek girdiler: yeni sürümdekiler + önceki kurulumun girdileri (+ ilk geçişte WordPress'in kök dosyaları)
managed="$OPS/installed.txt"
{
  (cd "$stage" && ls -A)
  [ -f "$managed" ] && cat "$managed"
  if [ -f "$WEBROOT/wp-config.php" ]; then
    (cd "$WEBROOT" && ls -A | grep -E '^(wp-admin|wp-includes|index\.php|wp-[a-z-]+\.php|xmlrpc\.php|license\.txt|readme\.html|wp-config[^/]*|\.htaccess[^/]*|\.litespeed_flag)$')
  fi
} | sort -u > "$OPS/targets.txt"

snap="$OPS/snapshots/$(date '+%Y%m%d-%H%M%S')"
mkdir -p "$snap/_wpc" && : > "$snap/.moved-out" && : > "$snap/.moved-in" && : > "$snap/.wpc-moved-out"

rollback() {
  local fail_dir
  fail_dir="$OPS/failed-$(date '+%Y%m%d-%H%M%S')"; mkdir -p "$fail_dir"
  while IFS= read -r e; do [ -n "$e" ] && { [ -e "$WEBROOT/$e" ] || [ -L "$WEBROOT/$e" ]; } && mv "$WEBROOT/$e" "$fail_dir/$e"; done < "$snap/.moved-in"
  while IFS= read -r e; do [ -n "$e" ] && { [ -e "$snap/$e" ] || [ -L "$snap/$e" ]; } && mv "$snap/$e" "$WEBROOT/$e"; done < "$snap/.moved-out"
  while IFS= read -r c; do [ -n "$c" ] && [ -e "$snap/_wpc/$c" ] && mv "$snap/_wpc/$c" "$WEBROOT/wp-content/$c"; done < "$snap/.wpc-moved-out"
  log "geri dönüş: önceki dosyalar yerine kondu; başarısız sürüm → $fail_dir"
}

# ---------------------------------------------------------------- kurulum (yer değiştirme; saniyenin altında)
# 1) eski girdileri anlık yedeğe taşı
while IFS= read -r e; do
  [ -z "$e" ] && continue
  is_preserved "$e" && continue
  { [ -e "$WEBROOT/$e" ] || [ -L "$WEBROOT/$e" ]; } || continue
  mv "$WEBROOT/$e" "$snap/$e" && echo "$e" >> "$snap/.moved-out" || { log "taşıma: HATA ($e)"; rollback; status "rolled-back" "$remote_sha" "move-out"; exit 1; }
done < "$OPS/targets.txt"
# WordPress'ten kalan wp-content: yalnızca uploads (eski görsel adresleri) kalır
if [ -d "$WEBROOT/wp-content" ] && grep -qx 'wp-config.php' "$snap/.moved-out"; then
  for p in "$WEBROOT"/wp-content/* "$WEBROOT"/wp-content/.[!.]*; do
    { [ -e "$p" ] || [ -L "$p" ]; } || continue
    c=${p##*/}; [ "$c" = "uploads" ] && continue
    mv "$p" "$snap/_wpc/$c" && echo "$c" >> "$snap/.wpc-moved-out"
  done
fi
# 2) yeni girdileri içeri al
: > "$OPS/installed.new"
for p in "$stage"/* "$stage"/.[!.]*; do
  { [ -e "$p" ] || [ -L "$p" ]; } || continue
  e=${p##*/}
  if is_preserved "$e"; then log "uyarı: yayında korunan ad ($e) — atlandı"; continue; fi
  mv "$p" "$WEBROOT/$e" && echo "$e" >> "$snap/.moved-in" && echo "$e" >> "$OPS/installed.new" \
    || { log "taşıma: HATA ($e)"; rollback; status "rolled-back" "$remote_sha" "move-in"; exit 1; }
done
rm -rf "$stage"
# 3) görseller: web kökündeki "uploads" → ~/thinktool-data/uploads
if [ ! -e "$WEBROOT/uploads" ] && [ ! -L "$WEBROOT/uploads" ]; then
  ln -s "$DATA/uploads" "$WEBROOT/uploads" && log "uploads bağlantısı oluşturuldu"
fi
log "kuruldu: $remote_sha / $rid ($nfiles dosya; anlık yedek $snap)"

# ---------------------------------------------------------------- canlı test
sleep 2
bad=0; checked=0
urls="$rel/_ops/urls.txt"
if [ -f "$urls" ]; then
  while IFS= read -r line; do
    [ -z "$line" ] && continue
    case "$line" in \#*) continue ;; esac
    path=$(echo "$line" | awk '{print $1}'); want=$(echo "$line" | awk '{print $2}'); loc=$(echo "$line" | awk '{print $3}')
    res=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 20 -H 'Cache-Control: no-cache' "$SITE_URL$path")
    code=${res%% *}; got=${res#* }
    checked=$((checked+1))
    if [ "$code" != "$want" ]; then bad=$((bad+1)); log "test: HATA $path → $code (beklenen $want)"; continue; fi
    if [ -n "$loc" ] && [ "$got" != "$SITE_URL$loc" ]; then bad=$((bad+1)); log "test: HATA $path → $got (beklenen $SITE_URL$loc)"; fi
  done < "$urls"
fi
live=$(curl -fsS --max-time 20 -H 'Cache-Control: no-cache' "$SITE_URL/version.txt?t=$(date +%s)" 2>/dev/null | head -1)
case "$live" in "$rid"*) : ;; *) bad=$((bad+1)); log "test: HATA version.txt canlıda '$live' (beklenen $rid)" ;; esac
api=$(curl -fsS --max-time 20 "$SITE_URL/api/version.php" 2>/dev/null)
case "$api" in *'"ok":true'*) : ;; *) bad=$((bad+1)); log "test: HATA api/version.php yanıtı: ${api:0:200}" ;; esac

if [ "$bad" -gt 0 ]; then
  log "test: $bad/$checked hata — GERİ DÖNÜLÜYOR"
  rollback
  echo "$remote_sha" >> "$OPS/bad_shas"
  status "rolled-back" "$remote_sha" "$bad hata"
  exit 1
fi

mv "$OPS/installed.new" "$managed"
echo "$remote_sha" > "$OPS/current_sha"
echo "$rid" > "$OPS/current_release"
status "live" "$remote_sha" "$checked test geçti"
log "test: $checked/$checked geçti — CANLI"

# ---------------------------------------------------------------- temizlik (son $KEEP sürüm ve anlık yedek)
ls -1dt "$OPS"/releases/*/ 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -rf
ls -1dt "$OPS"/snapshots/*/ 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -rf
ls -1dt "$OPS"/failed-*/ 2>/dev/null | tail -n +3 | xargs -r rm -rf
exit 0
