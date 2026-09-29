<?php
// thinktool.com.tr — ANLIK YAYIN (https://tetik.thinktool.com.tr/)
//
// Neden PHP: sunucuda (Alastyr, LiteSpeed + PHP 8.3) exec/shell_exec/proc_open… kapalı ve cron en sık 15 dakikada bir
// çalışabiliyor. Bu dosya ops/deploy.sh'in AYNI adımlarını saf PHP ile yapar:
//   main'in son commit'i → indir → doğrula → anlık yedek → yer değiştirerek kur → canlı test → hata olursa geri dön.
// deploy.sh ile aynı kilidi (~/thinktool-ops/.lock) ve aynı durum dosyalarını (current_sha, bad_shas, status.json,
// installed.txt, deploy.log, releases/, snapshots/) kullanır → 15 dakikalık cron ile çakışmaz; hangisi önce görürse o kurar.
//
//   GET /          → hemen {"durum":"tetiklendi"} döner, bağlantı kapanır, yayın arka planda sürer (20 sn'de bir çağrılabilir)
//   GET /?durum=1  → yalnızca okur: son yayın durumu, canlı sürüm, çalışan yayın var mı
//
// Şifre/anahtar kullanmaz; web isteği hiçbir ayarı değiştiremez (parametre almaz). Yalnızca açık yayın deposunun main
// dalındaki, sağlama toplamı tutan sürümü kurar. PHP yalnızca api/, yonetim/, _app/ altında olabilir; başka yerde
// çalıştırılabilir dosya, sembolik bağ ya da .htaccess'te betik yönergesi varsa sürüm reddedilir. Kendini güncellemez.
// İki betik birlikte değişir: ops/deploy.sh ↔ ops/tetik/index.php. İlk kurulum ve WordPress'ten geçiş yalnızca deploy.sh ile.

$CLI = PHP_SAPI === 'cli';
// Yerel deneme için ortam değişkenleri YALNIZCA komut satırında okunur
function ayar($k, $d) { global $CLI; $v = $CLI ? getenv($k) : false; return ($v === false || $v === '') ? $d : $v; }
$HOME_DIR = ayar('HOME_DIR', '/home/thinktoo');
$REPO = 'akaresocial/thinktool-yayin';
$BRANCH = 'main';
$SITE_URL = ayar('SITE_URL', 'https://thinktool.com.tr');
$WEBROOT = ayar('WEBROOT', "$HOME_DIR/public_html");
$OPS = ayar('OPS', "$HOME_DIR/thinktool-ops");
$DATA = ayar('DATA', "$HOME_DIR/thinktool-data");
$TEST_SHA = ayar('TEST_SHA', '');
$TEST_RELEASE_DIR = ayar('TEST_RELEASE_DIR', '');
$TEST_TGZ = ayar('TEST_TGZ', '');
$KEEP = 3;
// Hiçbir zaman taşınmaz/silinmez (deploy.sh'teki PRESERVE ile aynı)
const PRESERVE = ['.well-known', 'cgi-bin', '.user.ini', 'php.ini', 'uploads', 'wp-content', 'error_log'];

umask(022);
date_default_timezone_set('Europe/Istanbul');
ignore_user_abort(true);
@set_time_limit(0);
if (!$CLI) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex, nofollow');
}
@mkdir("$OPS/releases", 0755, true);
@mkdir("$OPS/snapshots", 0755, true);

// ---------------------------------------------------------------- yardımcılar
function zaman() { return date('Y-m-d\TH:i:sO'); }
function json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function logla($m) { global $OPS; @file_put_contents("$OPS/deploy.log", '[' . zaman() . "] tetik: $m\n", FILE_APPEND); }
function durum($state, $sha = '', $note = '') {
  global $OPS, $SITE_URL;
  @file_put_contents("$OPS/status.json", json(['time' => zaman(), 'state' => $state, 'sha' => $sha, 'note' => $note, 'site' => $SITE_URL, 'by' => 'tetik']) . "\n");
}
function yanit($kod, $veri) { global $CLI; if (!$CLI) http_response_code($kod); echo json($veri) . "\n"; }
// Çalışmanın sonu. Web'de yanıt baştan gönderildi (bağlantı kapalı); komut satırında sonucu yaz ve çıkış koduyla bit.
function bitir($kod, $veri) { global $CLI; if ($CLI) { echo json($veri) . "\n"; exit($kod >= 400 ? 1 : 0); } exit; }
function girdiler($d) { $l = @scandir($d); return $l === false ? [] : array_values(array_diff($l, ['.', '..'])); }
function var_mi($p) { return file_exists($p) || is_link($p); }
function korunan($e) {
  // + arama motoru doğrulama dosyaları (google*.html, yandex_*.html, BingSiteAuth.xml)
  return in_array($e, PRESERVE, true) || preg_match('/^(google[0-9a-f]+\.html|yandex_[0-9a-f]+\.html|BingSiteAuth\.xml)$/', $e) === 1;
}
function sil($p) {
  if (is_link($p) || is_file($p)) return @unlink($p);
  if (!is_dir($p)) return true;
  @chmod($p, 0755);
  $ok = true;
  foreach (girdiler($p) as $e) $ok = sil("$p/$e") && $ok;
  return @rmdir($p) && $ok;
}
// cp -a karşılığı (sembolik bağ kopyalanmaz: yayında bulunamaz, doğrulamada reddedilir)
function kopyala($src, $dst) {
  if (!is_dir($dst) && !@mkdir($dst, 0755, true)) return false;
  foreach (girdiler($src) as $e) {
    $s = "$src/$e"; $d = "$dst/$e";
    if (is_link($s)) return false;
    if (is_dir($s)) { if (!kopyala($s, $d)) return false; continue; }
    if (!@copy($s, $d)) return false;
    @chmod($d, 0644);
    @touch($d, (int) filemtime($s));
  }
  return true;
}
// [['f'|'l', göreli yol], …] — find -type f / -type l karşılığı
function agac($dir, $rel = '') {
  $out = [];
  foreach (girdiler($dir) as $e) {
    $p = "$dir/$e"; $r = $rel === '' ? $e : "$rel/$e";
    if (is_link($p)) $out[] = ['l', $r];
    elseif (is_dir($p)) $out = array_merge($out, agac($p, $r));
    else $out[] = ['f', $r];
  }
  return $out;
}
function http_al($url, $sure = 20, $dosya = null, $basliklar = []) {
  $c = curl_init($url);
  $o = [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => $sure,
        CURLOPT_USERAGENT => 'git/2.40 (thinktool-tetik)', CURLOPT_HTTPHEADER => $basliklar, CURLOPT_FAILONERROR => true];
  if ($dosya) $o[CURLOPT_FILE] = $dosya; else $o[CURLOPT_RETURNTRANSFER] = true;
  curl_setopt_array($c, $o);
  $r = curl_exec($c);
  return $r === false ? false : ($dosya ? true : (string) $r);
}
// Canlı test istekleri: yönlendirme İZLENMEZ, 6'şar paralel → [[http kodu, yönlendirme adresi], …]
function coklu_istek($urls) {
  $out = [];
  foreach (array_chunk($urls, 6, true) as $parca) {
    $mh = curl_multi_init(); $h = [];
    foreach ($parca as $i => $u) {
      $c = curl_init($u);
      curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'], CURLOPT_USERAGENT => 'thinktool-tetik']);
      curl_multi_add_handle($mh, $c);
      $h[$i] = $c;
    }
    do { $st = curl_multi_exec($mh, $calisan); if ($calisan) curl_multi_select($mh, 1.0); } while ($calisan && $st === CURLM_OK);
    foreach ($h as $i => $c) {
      $out[$i] = [(int) curl_getinfo($c, CURLINFO_HTTP_CODE), (string) curl_getinfo($c, CURLINFO_REDIRECT_URL)];
      curl_multi_remove_handle($mh, $c);
    }
  }
  return $out;
}
// codeload arşivi (git archive → tar.gz) akış hâlinde açılır: bellek kullanımı sabit, uzun yollar (pax "x" path) doğru
// okunur (PharData bunları yok sayıp dosyayı yanlış yere yazıyor). Tek üst klasör (depo-sha/) atılır. Yalnızca düz dosya
// ve klasör kabul edilir; bağ, aygıt, mutlak yol, "..", yinelenen yol ya da bozuk başlık → hata (null = başarılı).
function tar_ac($tgz, $hedef) {
  $gz = @gzopen($tgz, 'rb');
  if (!$gz) return 'arşiv açılamadı';
  $oku = function ($n) use ($gz) {
    $b = '';
    while (strlen($b) < $n) { $p = gzread($gz, $n - strlen($b)); if ($p === false || $p === '') break; $b .= $p; }
    return $b;
  };
  $atla = function ($n) use ($oku) {
    while ($n > 0) { $p = $oku(min($n, 1048576)); if ($p === '') return false; $n -= strlen($p); }
    return true;
  };
  $pax = []; $ust = null; $adet = 0;
  try {
    while (true) {
      $h = $oku(512);
      if (strlen($h) < 512) return 'arşiv yarıda bitti';
      if ($h === str_repeat("\0", 512)) return $adet > 0 ? null : 'arşiv boş';
      $top = 0;
      for ($i = 0; $i < 512; $i++) $top += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]);
      if ($top !== octdec(trim(substr($h, 148, 8), " \0"))) return 'arşiv başlığı bozuk';
      $boy = trim(substr($h, 124, 12), " \0");
      if (!preg_match('/^[0-7]*$/', $boy)) return 'desteklenmeyen dosya boyutu';
      $boy = (int) octdec($boy === '' ? '0' : $boy);
      $dolgu = (512 - $boy % 512) % 512;
      $tur = $h[156];
      $ad = rtrim(substr($h, 0, 100), "\0");
      if (substr($h, 257, 6) === "ustar\0" && ($on = rtrim(substr($h, 345, 155), "\0")) !== '') $ad = "$on/$ad";
      if ($tur === 'g') { if (!$atla($boy + $dolgu)) return 'arşiv yarıda bitti'; continue; }
      if ($tur === 'x') {
        // kayıtlar: "<uzunluk> anahtar=değer\n"; sonraki girdiye uygulanır
        $v = $oku($boy);
        if (strlen($v) < $boy || !$atla($dolgu)) return 'arşiv yarıda bitti';
        $pax = [];
        for ($o = 0; $o < strlen($v);) {
          if (!preg_match('/^(\d+) /', substr($v, $o, 24), $m) || (int) $m[1] <= strlen($m[0])) return 'pax başlığı bozuk';
          $kayit = substr($v, $o + strlen($m[0]), (int) $m[1] - strlen($m[0]) - 1);
          $o += (int) $m[1];
          $esit = strpos($kayit, '=');
          if ($esit !== false) $pax[substr($kayit, 0, $esit)] = substr($kayit, $esit + 1);
        }
        continue;
      }
      if (isset($pax['path'])) $ad = $pax['path'];
      if (isset($pax['size']) && (string) (int) $pax['size'] !== (string) $boy) return 'desteklenmeyen dosya boyutu (pax)';
      $pax = [];
      if ($tur !== '0' && $tur !== "\0" && $tur !== '5') return "desteklenmeyen girdi türü '" . addcslashes($tur, "\0..\37") . "' ($ad)";
      $ad = rtrim($ad, '/');
      $parca = explode('/', $ad);
      if ($ad === '' || strpos($ad, "\0") !== false || in_array('', $parca, true) || in_array('.', $parca, true) || in_array('..', $parca, true)) return "geçersiz yol ($ad)";
      if ($ust === null) $ust = $parca[0];
      if ($parca[0] !== $ust) return 'birden çok üst klasör';
      $yol = implode('/', array_slice($parca, 1));
      if ($yol === '') { if ($tur !== '5' || !$atla($boy + $dolgu)) return 'beklenmeyen arşiv yapısı'; continue; }
      $tam = "$hedef/$yol";
      if ($tur === '5') {
        if (!is_dir($tam) && !@mkdir($tam, 0755, true)) return "klasör oluşturulamadı ($yol)";
        if (!$atla($boy + $dolgu)) return 'arşiv yarıda bitti';
        continue;
      }
      if (!is_dir(dirname($tam)) && !@mkdir(dirname($tam), 0755, true)) return "klasör oluşturulamadı ($yol)";
      $fh = @fopen($tam, 'xb');
      if (!$fh) return "dosya yazılamadı ya da yineleniyor ($yol)";
      for ($k = $boy; $k > 0;) {
        $p = $oku(min($k, 1048576));
        if ($p === '' || fwrite($fh, $p) !== strlen($p)) { fclose($fh); return 'arşiv yarıda bitti ya da disk dolu'; }
        $k -= strlen($p);
      }
      fclose($fh);
      @chmod($tam, 0644);
      if (!$atla($dolgu)) return 'arşiv yarıda bitti';
      $adet++;
    }
  } finally {
    gzclose($gz);
  }
}
// deploy.sh'teki grep kalıbının karşılığı (ikisi birlikte değişir)
function htaccess_tehlikeli($t) {
  return preg_match('/^[ \t]*(AddHandler|SetHandler|ForceType[^#\n]*(php|cgi)|Action|ScriptAlias|php_value|php_flag|php_admin|AddType[^#\n]*php|(Add|Set)OutputFilter[^#\n]*INCLUDES)/im', $t)
      || preg_match('/^[ \t]*Options[ \t][^#\n]*(^|[ \t]|\+)(ExecCGI|Includes)([ \t]|$)/im', $t)
      || preg_match('/^[ \t]*Rewrite(Rule|Cond)[ \t][^#\n]*\[([^\]\n]*,)?[ \t]*H=/im', $t);
}
function satirlar($f) { return array_values(array_filter(array_map('trim', @file($f) ?: []), 'strlen')); }
function ekle($f, $e) { file_put_contents($f, "$e\n", FILE_APPEND); }
function geri_don() {
  global $WEBROOT, $OPS, $SNAP;
  $fail = "$OPS/failed-" . date('Ymd-His');
  if (file_exists($fail)) $fail .= '-t';
  @mkdir($fail, 0755, true);
  foreach (satirlar("$SNAP/.moved-in") as $e) if (var_mi("$WEBROOT/$e")) @rename("$WEBROOT/$e", "$fail/$e");
  foreach (satirlar("$SNAP/.moved-out") as $e) if (var_mi("$SNAP/$e")) @rename("$SNAP/$e", "$WEBROOT/$e");
  // geri konan .htaccess'in tarihi eski: LiteSpeed değişikliği fark etsin
  if (is_file("$WEBROOT/.htaccess")) @touch("$WEBROOT/.htaccess");
  logla("geri dönüş: önceki dosyalar yerine kondu; başarısız sürüm → $fail");
}
// Son $tut dizin kalır. Sunucu izni yüzünden silinemeyen dizin "silinemeyen/" altına alınır (her çalışmada yeniden denenmesin)
function temizle($desen, $tut) {
  global $OPS;
  $d = glob($desen, GLOB_ONLYDIR) ?: [];
  usort($d, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
  foreach (array_slice($d, $tut) as $x) {
    if (sil($x)) continue;
    @mkdir("$OPS/silinemeyen", 0755, true);
    @rename($x, "$OPS/silinemeyen/" . basename($x) . '-' . date('YmdHis'));
    logla("temizlik: $x tamamen silinemedi (sunucu izni) → $OPS/silinemeyen/");
  }
}

// ---------------------------------------------------------------- yalnızca okuma: durum
// "calisiyor": bir tetik şu an çalışıyor mu (tetik kilidi tutuluyor mu). Cron'un kilidine (.lock) bakılmaz: o anda
// denemek cron'un "flock -n" çalışmasını boşa düşürebilirdi.
function tetik_calisiyor() {
  global $OPS;
  $f = @fopen("$OPS/.tetik.lock", 'c');
  if (!$f) return false;
  $mesgul = !flock($f, LOCK_EX | LOCK_NB);
  if (!$mesgul) flock($f, LOCK_UN);
  fclose($f);
  return $mesgul;
}
if (!$CLI && isset($_GET['durum'])) {
  $st = json_decode((string) @file_get_contents("$OPS/status.json"), true);
  yanit(200, ['durum' => $st ?: null, 'canli_sha' => trim((string) @file_get_contents("$OPS/current_sha")), 'canli_surum' => trim((string) @file_get_contents("$OPS/current_release")), 'calisiyor' => tetik_calisiyor()]);
  exit;
}

// ---------------------------------------------------------------- tetik: aynı anda tek tetik, 20 sn sınırı, hemen yanıt
// Kilit yanıttan ÖNCE alınır ve çalışma bitene kadar tutulur: eşzamanlı istekler 429 alır, en fazla bir süreç bekler.
$tkilit = fopen("$OPS/.tetik.lock", 'c');
if (!$tkilit || !flock($tkilit, LOCK_EX | LOCK_NB)) {
  if ($CLI) bitir(429, ['durum' => 'calisiyor']);
  yanit(429, ['durum' => 'calisiyor']);
  exit;
}
$damga = "$OPS/.tetik";
if (!$CLI && is_file($damga) && time() - (int) filemtime($damga) < 20) {
  yanit(429, ['durum' => 'bekle', 'saniye' => 20 - (time() - (int) filemtime($damga))]);
  exit;
}
@touch($damga);
if (!$CLI) {
  yanit(202, ['durum' => 'tetiklendi', 'zaman' => zaman(), 'izle' => '?durum=1']);
  if (function_exists('litespeed_finish_request')) litespeed_finish_request();
  elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

// Tek örnek: deploy.sh ile aynı kilit. Cron çalışıyorsa bitmesini en fazla 2 dk bekle (o da aynı sürümü kuruyor olabilir).
$kilit = fopen("$OPS/.lock", 'c');
$son = time() + 120;
while (!$kilit || !flock($kilit, LOCK_EX | LOCK_NB)) {
  if (time() > $son) { logla('kilit: başka bir çalışma 2 dakikadır sürüyor — vazgeçildi (cron kuracak)'); bitir(409, ['durum' => 'mesgul']); }
  sleep(2);
}
file_put_contents("$OPS/.running", 'tetik pid=' . getmypid() . ' başlangıç=' . zaman() . "\n");
$YARIM = false; $SHA = ''; $SNAP = '';
register_shutdown_function(function () {
  global $OPS, $YARIM, $SHA;
  // yer değiştirmeden canlı test kararına kadar ölümcül hata / süre aşımı → eski dosyaları geri koy
  if ($YARIM) { $YARIM = false; logla('HATA: kurulum/test yarıda kesildi — geri dönülüyor'); geri_don(); durum('rolled-back', $SHA, 'yarıda kesildi'); }
  @unlink("$OPS/.running");
});

// Gereken PHP işlevleri: biri kapalıysa hiçbir şeye dokunmadan çık (cron kurar)
$eksik = array_values(array_filter(['curl_init', 'curl_exec', 'curl_multi_init', 'curl_multi_exec', 'curl_multi_select', 'curl_multi_add_handle',
  'curl_multi_remove_handle', 'gzopen', 'gzread', 'hash_file', 'flock', 'symlink', 'touch', 'rename'], function ($f) { return !function_exists($f); }));
if ($eksik) { logla('HATA: sunucuda kapalı PHP işlevi: ' . implode(', ', $eksik) . ' — tetik çalışamaz (cron kurar)'); durum('no-functions', '', implode(' ', $eksik)); bitir(500, ['durum' => 'islev-yok', 'eksik' => $eksik]); }

$lg = "$OPS/deploy.log";
if (is_file($lg) && filesize($lg) > 1048576) {
  $f = fopen($lg, 'rb'); fseek($f, -262144, SEEK_END); $t = stream_get_contents($f); fclose($f);
  file_put_contents("$lg.tmp", $t); rename("$lg.tmp", $lg);
}

// WordPress'ten geçiş ve ilk kurulum (veritabanı + başlangıç içeriği) yalnızca deploy.sh ile yapılır
if (is_file("$WEBROOT/wp-config.php")) bitir(409, ['durum' => 'wordpress-var-cron-ile']);
if (!is_file("$DATA/thinktool.sqlite")) bitir(409, ['durum' => 'ilk-kurulum-cron-ile']);

// ---------------------------------------------------------------- uzak sürüm (git ls-remote karşılığı)
function uzak_sha() {
  global $TEST_SHA, $REPO, $BRANCH;
  if ($TEST_SHA !== '') return $TEST_SHA;
  $refs = http_al("https://github.com/$REPO.git/info/refs?service=git-upload-pack", 30);
  if ($refs !== false && preg_match('#([0-9a-f]{40}) refs/heads/' . preg_quote($BRANCH, '#') . '(\x00|\n)#', $refs, $m)) return $m[1];
  $r = http_al("https://api.github.com/repos/$REPO/commits/$BRANCH", 20, null, ['Accept: application/vnd.github.sha']);
  return $r === false ? '' : substr(preg_replace('/[^0-9a-f]/', '', $r), 0, 40);
}
$current = trim((string) @file_get_contents("$OPS/current_sha"));
$remote = uzak_sha();
// İş akışı gönderir göndermez tetikler; GitHub'ın yeni commit'i göstermesi bir an gecikebilir → bir kez daha bak
if (!$CLI && $remote === $current) { sleep(5); $remote = uzak_sha(); }
if (strlen($remote) !== 40) { logla('uzak: sürüm okunamadı (ağ/GitHub); cron yeniden deneyecek'); bitir(502, ['durum' => 'uzak-okunamadi']); }
if ($remote === $current) bitir(200, ['durum' => 'guncel', 'sha' => $remote]);
if (in_array($remote, satirlar("$OPS/bad_shas"), true)) bitir(200, ['durum' => 'reddedilmis-surum', 'sha' => $remote]);
$SHA = $remote;
logla("yeni sürüm: $remote (canlı: " . ($current ?: 'yok') . ')');
durum('installing', $remote);

// ---------------------------------------------------------------- indir + aç (codeload arşivinde tek üst klasör var → onun içi)
$rel = "$OPS/releases/$remote";
if (!is_dir("$rel/public") && $TEST_RELEASE_DIR !== '') kopyala($TEST_RELEASE_DIR, $rel);
if (!is_dir("$rel/public")) {
  // yarıda kalmış eski indirmeler
  foreach (glob("$OPS/releases/*.tar.gz") ?: [] as $x) @unlink($x);
  foreach (glob("$OPS/releases/*.tmp", GLOB_ONLYDIR) ?: [] as $x) sil($x);
  $tmp = "$rel.tmp"; @mkdir($tmp, 0755, true);
  $tgz = "$OPS/releases/$remote.tar.gz";
  if ($TEST_TGZ !== '') $hata = @copy($TEST_TGZ, $tgz) ? null : 'indirme: HATA (deneme arşivi)';
  else {
    $fh = @fopen($tgz, 'wb');
    $hata = ($fh && http_al("https://codeload.github.com/$REPO/tar.gz/$remote", 180, $fh)) ? null : 'indirme: HATA';
    if ($fh) fclose($fh);
  }
  if (!$hata && ($e = tar_ac($tgz, $tmp)) !== null) $hata = "açma: HATA — $e";
  @unlink($tgz);
  if (!$hata && !@rename($tmp, $rel)) $hata = 'açma: HATA — taşınamadı';
  sil($tmp);
  // indirme/açma hatası sürümün suçu olmayabilir: reddedilmez, sonraki tetik ya da cron (GNU tar) yeniden dener
  if ($hata) { logla($hata); sil($rel); durum('download-failed', $remote, $hata); bitir(502, ['durum' => 'indirme-hatasi', 'not' => $hata]); }
}

// ---------------------------------------------------------------- doğrulama (deploy.sh ile aynı kurallar)
function reddet($neden) {
  global $OPS, $SHA;
  logla("doğrulama: HATA — $neden");
  ekle("$OPS/bad_shas", $SHA);
  durum('invalid', $SHA, $neden);
  bitir(422, ['durum' => 'reddedildi', 'neden' => $neden, 'sha' => $SHA]);
}
foreach (['index.html', '.htaccess', '404.html', 'sitemap.xml', 'version.txt', 'api/version.php', '_app/bootstrap.php', '_app/cli.php', 'yonetim/index.php'] as $f) {
  if (!is_file("$rel/public/$f")) reddet("$f yok");
}
$rid = preg_replace('/[^0-9a-f]/', '', (string) @file_get_contents("$rel/_ops/release-id"));
if (strlen($rid) < 8) reddet('_ops/release-id yok');
if (strpos((string) @file_get_contents("$rel/public/version.txt"), $rid) !== 0) reddet('version.txt yayın kimliğiyle uyuşmuyor');
$dosyalar = [];
foreach (agac("$rel/public") as $g) { if ($g[0] === 'l') reddet("yayında sembolik bağ var: {$g[1]}"); $dosyalar[] = $g[1]; }
$n = count($dosyalar);
if ($n < 100) reddet("dosya sayısı çok az ($n)");
// Betikler yalnızca api/, yonetim/, _app/ altında olabilir
$calis = array_filter(preg_grep('/\.(php\d?|pht|phtml|phar|cgi|pl|py|sh|shtml)$/i', $dosyalar), function ($f) { return !preg_match('#^(api|yonetim|_app)/#', $f); });
if ($calis) reddet('izin verilmeyen yerde betik: ' . implode(' ', array_slice($calis, 0, 3)));
foreach ($dosyalar as $f) {
  if (basename($f) === '.htaccess' && htaccess_tehlikeli((string) file_get_contents("$rel/public/$f"))) reddet(".htaccess içinde betik çalıştırma yönergesi var ($f)");
}
if (!is_file("$rel/_ops/SHA256SUMS")) reddet('SHA256SUMS yok');
$toplamlar = satirlar("$rel/_ops/SHA256SUMS");
if (count($toplamlar) !== $n) reddet('SHA256SUMS dosya sayısı (' . count($toplamlar) . ") ile paket ($n) uyuşmuyor");
foreach ($toplamlar as $s) {
  if (!preg_match('#^([0-9a-f]{64})  \./(.+)$#', $s, $m) || preg_match('#(^|/)\.\.(/|$)#', $m[2])) reddet('sağlama toplamı dosyası bozuk');
  $p = "$rel/public/{$m[2]}";
  if (!is_file($p) || hash_file('sha256', $p) !== $m[1]) reddet("sağlama toplamı uyuşmuyor ({$m[2]})");
}
// Sunucudaki betikler depodan kendini GÜNCELLEMEZ; fark yalnızca günlüğe yazılır
if (is_file("$rel/_ops/deploy.sh") && @sha1_file("$rel/_ops/deploy.sh") !== @sha1_file("$OPS/deploy.sh")) logla('betik: depodaki deploy.sh farklı — otomatik güncellenmedi');
if (is_file("$rel/_ops/tetik/index.php") && @sha1_file("$rel/_ops/tetik/index.php") !== @sha1_file(__FILE__)) logla('tetik: depodaki index.php farklı — otomatik güncellenmedi');

// güvenlik kapısı
if (preg_replace('/[^0-9]/', '', (string) @file_get_contents("$rel/_ops/enabled")) !== '1') {
  logla('kapı: _ops/enabled != 1 — kurulum yapılmadı (yalnızca indirildi ve doğrulandı)');
  durum('gated', $remote);
  bitir(200, ['durum' => 'kapi-kapali', 'sha' => $remote]);
}

// ---------------------------------------------------------------- hazırlık
$stage = "$OPS/stage-$rid";
sil($stage);
if (!kopyala("$rel/public", $stage)) { logla('hazırlık: HATA'); sil($stage); durum('stage-failed', $remote); bitir(500, ['durum' => 'hazirlik-hatasi']); }
// cPanel'in yazdığı bloklar (MultiPHP: PHP sürümü ve php.ini ayarları) eski .htaccess'ten korunur
$eski = (string) @file_get_contents("$WEBROOT/.htaccess");
if (preg_match_all('/^.*BEGIN cPanel-generated.*$[\s\S]*?^.*END cPanel-generated.*$/m', $eski, $bloklar) && $bloklar[0]) {
  file_put_contents("$stage/.htaccess", implode("\n", $bloklar[0]) . "\n\n" . file_get_contents("$stage/.htaccess"));
}
if (strpos((string) file_get_contents("$stage/.htaccess"), 'cPanel-generated handler') === false) logla("uyarı: .htaccess'te cPanel PHP sürümü satırı yok — alan adının PHP sürümü MultiPHP'den 8.3 olmalı");

// Değiştirilecek girdiler: yeni sürümdekiler + önceki kurulumun girdileri (web kökündeki diğer her şey yerinde kalır)
$hedefler = array_values(array_unique(array_merge(girdiler($stage), satirlar("$OPS/installed.txt"))));
$SNAP = "$OPS/snapshots/" . date('Ymd-His');
if (file_exists($SNAP)) $SNAP .= '-t';
@mkdir($SNAP, 0755, true);
foreach (['.moved-out', '.moved-in'] as $f) file_put_contents("$SNAP/$f", '');

// ---------------------------------------------------------------- kurulum (yer değiştirme; saniyenin altında)
$YARIM = true;
// 1) eski girdileri anlık yedeğe taşı
foreach ($hedefler as $e) {
  if ($e === '' || strpos($e, '/') !== false || korunan($e) || !var_mi("$WEBROOT/$e")) continue;
  if (@rename("$WEBROOT/$e", "$SNAP/$e")) { ekle("$SNAP/.moved-out", $e); continue; }
  logla("taşıma: HATA ($e)"); $YARIM = false; geri_don(); durum('rolled-back', $remote, 'move-out'); bitir(500, ['durum' => 'geri-donuldu', 'neden' => "taşıma: $e"]);
}
// 2) yeni girdileri içeri al
$kurulan = [];
foreach (girdiler($stage) as $e) {
  if (korunan($e)) { logla("uyarı: yayında korunan ad ($e) — atlandı"); continue; }
  if (@rename("$stage/$e", "$WEBROOT/$e")) { ekle("$SNAP/.moved-in", $e); $kurulan[] = $e; continue; }
  logla("taşıma: HATA ($e)"); $YARIM = false; geri_don(); durum('rolled-back', $remote, 'move-in'); bitir(500, ['durum' => 'geri-donuldu', 'neden' => "taşıma: $e"]);
}
// $YARIM canlı test kararına kadar açık kalır: bu arada ölümcül hata / süre aşımı olursa kapanışta geri dönülür
sil($stage);
// arşivden gelen dosyaların tarihi commit zamanıdır: sunucu (LiteSpeed) yeni .htaccess'i hemen okusun
@touch("$WEBROOT/.htaccess");
// görseller: web kökündeki "uploads" → ~/thinktool-data/uploads
// (geri dönüşte kaldırılsın diye .moved-in'e yazılır; installed.txt'ye girmez — korunan ad)
if (!var_mi("$WEBROOT/uploads") && is_dir("$DATA/uploads") && @symlink("$DATA/uploads", "$WEBROOT/uploads")) { ekle("$SNAP/.moved-in", 'uploads'); logla('uploads bağlantısı oluşturuldu'); }
logla("kuruldu: $remote / $rid ($n dosya; anlık yedek $SNAP)");

// ---------------------------------------------------------------- canlı test (_ops/urls.txt: "yol beklenen_kod [beklenen_hedef]")
// LiteSpeed .htaccess'i birkaç saniye önbellekte tutabilir: hata olursa testler aralıklı olarak 3 kez denenir.
$testler = [];
foreach (satirlar("$rel/_ops/urls.txt") as $s) {
  if ($s[0] === '#') continue;
  $p = preg_split('/\s+/', $s);
  $testler[] = [$p[0], $p[1] ?? '', $p[2] ?? ''];
}
$checked = count($testler);
$bad = 0; $hatalar = [];
for ($deneme = 1; $deneme <= 3; $deneme++) {
  sleep($deneme === 1 ? 3 : 20);
  $bad = 0; $hatalar = [];
  $sonuc = coklu_istek(array_map(function ($t) use ($SITE_URL) { return $SITE_URL . $t[0]; }, $testler));
  foreach ($testler as $i => $t) {
    [$yol, $bek, $hedef] = $t; [$kod, $yon] = $sonuc[$i];
    if ((string) $kod !== $bek) { $bad++; $hatalar[] = "$yol → $kod (beklenen $bek)"; continue; }
    if ($hedef !== '' && $yon !== $SITE_URL . $hedef) { $bad++; $hatalar[] = "$yol → $yon (beklenen $SITE_URL$hedef)"; }
  }
  $live = http_al("$SITE_URL/version.txt?t=" . time(), 20, null, ['Cache-Control: no-cache']);
  $live = $live === false ? '' : (string) strtok($live, "\n");
  if (strpos($live, $rid) !== 0) { $bad++; $hatalar[] = "version.txt canlıda '$live' (beklenen $rid)"; }
  $api = http_al("$SITE_URL/api/version.php", 20);
  if ($api === false || strpos($api, '"ok":true') === false) { $bad++; $hatalar[] = 'api/version.php yanıtı: ' . substr((string) $api, 0, 200); }
  if ($bad === 0) break;
  logla("test: deneme $deneme — $bad hata ({$hatalar[0]})");
}

if ($bad > 0) {
  foreach ($hatalar as $h) logla("test: HATA $h");
  logla("test: $bad/$checked hata — GERİ DÖNÜLÜYOR");
  $YARIM = false;
  geri_don();
  ekle("$OPS/bad_shas", $remote);
  durum('rolled-back', $remote, "$bad hata");
  bitir(500, ['durum' => 'geri-donuldu', 'hatalar' => array_slice($hatalar, 0, 10)]);
}

file_put_contents("$OPS/installed.txt", implode("\n", $kurulan) . "\n");
file_put_contents("$OPS/current_sha", "$remote\n");
file_put_contents("$OPS/current_release", "$rid\n");
$YARIM = false;
durum('live', $remote, "$checked test geçti");
logla("test: $checked/$checked geçti — CANLI (deneme $deneme)");

// ---------------------------------------------------------------- temizlik (son $KEEP sürüm ve anlık yedek)
temizle("$OPS/releases/*", $KEEP);
temizle("$OPS/snapshots/*", $KEEP);
temizle("$OPS/failed-*", 2);
bitir(200, ['durum' => 'canli', 'sha' => $remote, 'yayin' => $rid, 'test' => "$checked/$checked"]);
