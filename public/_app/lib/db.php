<?php

declare(strict_types=1);

/**
 * SQLite veritabanı. Tek dosya; yedeklemesi ve taşınması kolay.
 * Şema sürümlüdür: yeni sürümde eklenen adımlar ilk bağlantıda kendiliğinden uygulanır.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $file = DATA_DIR . '/thinktool.sqlite';
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Veritabanı yalnızca hesap sahibine açık (WAL/SHM dosyaları aynı izni alır).
    if (is_file($file) && (fileperms($file) & 0077)) {
        @chmod($file, 0600);
    }
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    db_migrate($pdo);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Yazma işlemi: iç içe çağrılarda dıştaki işlem kullanılır. */
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn();
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function meta_get(string $key): ?string
{
    $v = q_val('SELECT value FROM meta WHERE key = ?', [$key]);
    return $v === null ? null : (string) $v;
}

function meta_set(string $key, string $value): void
{
    q('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $version = (int) ($pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetchColumn() ?: 0);
    $steps = db_schema_steps();
    if ($version >= count($steps)) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // Başka bir istek aynı anda yükseltmiş olabilir
        $version = (int) ($pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetchColumn() ?: 0);
        for ($i = $version; $i < count($steps); $i++) {
            foreach ($steps[$i] as $step) {
                is_string($step) ? $pdo->exec($step) : $step($pdo);
            }
        }
        $st = $pdo->prepare("INSERT INTO meta (key, value) VALUES ('schema_version', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $st->execute([(string) count($steps)]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Şema adımları (SQL ya da function (PDO $pdo)). Yalnızca SONA ekleyin; mevcut adımları değiştirmeyin. */
function db_schema_steps(): array
{
    return [
        [
            'CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
            "CREATE TABLE media (
                id INTEGER PRIMARY KEY,
                file TEXT NOT NULL UNIQUE,
                alt TEXT NOT NULL DEFAULT '',
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                mime TEXT NOT NULL DEFAULT '',
                variants TEXT NOT NULL DEFAULT '[]',
                created_at TEXT NOT NULL
            )",
            "CREATE TABLE categories (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                sort_order INTEGER NOT NULL DEFAULT 100,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE products (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                price_usd REAL NOT NULL DEFAULT 0,
                compare_at_usd REAL,
                stock_status TEXT NOT NULL DEFAULT 'in_stock',
                stock_quantity INTEGER,
                published INTEGER NOT NULL DEFAULT 1,
                featured INTEGER NOT NULL DEFAULT 0,
                category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
                sort_order INTEGER NOT NULL DEFAULT 100,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                published INTEGER NOT NULL DEFAULT 1,
                published_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE pages (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                published INTEGER NOT NULL DEFAULT 1,
                show_in_footer INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 100,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE orders (
                id INTEGER PRIMARY KEY,
                order_number INTEGER NOT NULL UNIQUE,
                access_key TEXT NOT NULL,
                status TEXT NOT NULL,
                payment_method TEXT NOT NULL,
                total_try REAL NOT NULL,
                subtotal_usd REAL NOT NULL DEFAULT 0,
                exchange_rate REAL NOT NULL DEFAULT 0,
                shipping_try REAL NOT NULL DEFAULT 0,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT NOT NULL,
                shipping_address TEXT NOT NULL DEFAULT '{}',
                invoice TEXT NOT NULL DEFAULT '{}',
                customer_note TEXT NOT NULL DEFAULT '',
                admin_note TEXT NOT NULL DEFAULT '',
                carrier TEXT NOT NULL DEFAULT '',
                tracking_number TEXT NOT NULL DEFAULT '',
                tracking_url TEXT NOT NULL DEFAULT '',
                paytr TEXT NOT NULL DEFAULT '{}',
                consent TEXT NOT NULL DEFAULT '{}',
                source TEXT NOT NULL DEFAULT 'web',
                paid_at TEXT,
                shipped_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            'CREATE INDEX orders_status ON orders(status)',
            'CREATE INDEX orders_email ON orders(email)',
            "CREATE TABLE order_items (
                id INTEGER PRIMARY KEY,
                order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                product_id INTEGER,
                title TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                unit_price_usd REAL NOT NULL DEFAULT 0,
                unit_price_try REAL NOT NULL DEFAULT 0,
                line_total_try REAL NOT NULL DEFAULT 0
            )",
            'CREATE INDEX order_items_order ON order_items(order_id)',
            "CREATE TABLE order_events (
                id INTEGER PRIMARY KEY,
                order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                type TEXT NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL
            )",
            'CREATE INDEX order_events_order ON order_events(order_id)',
            "CREATE TABLE contact_messages (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                phone TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '',
                product TEXT NOT NULL DEFAULT '',
                message TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'new',
                ip TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            )",
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_login_at TEXT
            )",
            'CREATE TABLE rate_limits (key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, count INTEGER NOT NULL)',
            "CREATE TABLE mail_log (
                id INTEGER PRIMARY KEY,
                recipients TEXT NOT NULL,
                subject TEXT NOT NULL,
                status TEXT NOT NULL,
                error TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            )",
        ],
        // 2 — Peşin fiyatına 3 taksit (2026-10): maxInstallment → installmentLimit (aynı değer; 3'ten az olamaz),
        // cashInstallments = 3; eski kart açıklaması ve duyurudaki "Kredi kartına taksit" metni güncellenir.
        [
            static function (PDO $pdo): void {
                $raw = $pdo->query("SELECT value FROM settings WHERE key = 'site'")->fetchColumn();
                if ($raw === false) {
                    return; // ayar kaydı yok: varsayılanlar geçerli
                }
                $s = json_decode((string) $raw, true) ?: [];
                $s['cashInstallments'] ??= 3;
                $eski = (int) ($s['maxInstallment'] ?? 0);
                $s['installmentLimit'] ??= $eski === 0 ? 0 : max($eski, 3);
                unset($s['maxInstallment']);
                if (($s['cardDescription'] ?? '') === 'Tüm kredi kartlarına taksit imkânı. Ödeme PayTR güvencesiyle alınır.') {
                    $s['cardDescription'] = 'Ödeme PayTR güvencesiyle alınır.';
                }
                if (isset($s['announcement']['text']) && is_string($s['announcement']['text'])) {
                    $s['announcement']['text'] = (string) preg_replace('/(^|·)\\s*Kredi kartına taksit\\s*(?=·|$)/u', '$1 Kredi kartına peşin fiyatına 3 taksit ', $s['announcement']['text']);
                    $s['announcement']['text'] = trim((string) preg_replace('/\\s+/u', ' ', $s['announcement']['text']));
                }
                $st = $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'site'");
                $st->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
                // statik site yeniden derlensin
                $v = (int) ($pdo->query("SELECT value FROM meta WHERE key = 'content_version'")->fetchColumn() ?: 0) + 1;
                $up = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
                $up->execute(['content_version', (string) $v]);
                $up->execute(['content_updated_at', gmdate('Y-m-d\\TH:i:s\\Z')]);
            },
        ],
        // 3 — Havale/EFT ve iletişim formu kaldırıldı (2026-10): sipariş kartla ya da WhatsApp'tan verilir.
        // Havale kapatılır; yasal sayfalardaki havale ve iletişim formu ifadeleri (değiştirilmemişlerse) güncellenir.
        [
            static function (PDO $pdo): void {
                $raw = $pdo->query("SELECT value FROM settings WHERE key = 'site'")->fetchColumn();
                if ($raw !== false) {
                    $s = json_decode((string) $raw, true) ?: [];
                    $s['transferEnabled'] = false;
                    $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'site'")->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
                }
                $edits = [
                    'mesafeli-satis-sozlesmesi' => [' Havale/EFT ile ödemede, ödemenin SATICI hesabına geçmesiyle sipariş işleme alınır.' => ''],
                    'on-bilgilendirme-formu' => ['taksit seçenekleriyle) veya havale/EFT ile yapılabilir.' => 'taksit seçenekleriyle) ile yapılabilir.'],
                    'teslimat-ve-iade' => ['Kartla ödenen siparişler ödeme onayından, havale/EFT ile ödenen siparişler ödemenin hesabımıza geçmesinden sonra hazırlanır.' => 'Siparişler ödemenin onaylanmasından sonra hazırlanır.'],
                    'kvkk-aydinlatma-metni' => ['İletişim formu ve telefon/WhatsApp üzerinden iletilen' => 'Telefon ve WhatsApp üzerinden iletilen'],
                ];
                $get = $pdo->prepare('SELECT data FROM pages WHERE slug = ?');
                $put = $pdo->prepare('UPDATE pages SET data = ?, updated_at = ? WHERE slug = ?');
                foreach ($edits as $slug => $map) {
                    $get->execute([$slug]);
                    $data = json_decode((string) ($get->fetchColumn() ?: ''), true);
                    if (!is_array($data) || !is_string($data['contentHtml'] ?? null)) {
                        continue;
                    }
                    $html = strtr($data['contentHtml'], $map);
                    if ($html !== $data['contentHtml']) {
                        $data['contentHtml'] = $html;
                        $put->execute([json_encode($data, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d\\TH:i:s\\Z'), $slug]);
                    }
                }
                // statik site yeniden derlensin
                $v = (int) ($pdo->query("SELECT value FROM meta WHERE key = 'content_version'")->fetchColumn() ?: 0) + 1;
                $up = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
                $up->execute(['content_version', (string) $v]);
                $up->execute(['content_updated_at', gmdate('Y-m-d\\TH:i:s\\Z')]);
            },
        ],
        // 4 — Tüm ürünlere %2 zam (2026-10-05): USD fiyatı (ve varsa üstü çizili fiyat) 1,02 ile çarpılır, sente yuvarlanır.
        // Verilmiş siparişlerin fiyatları değişmez.
        [
            static function (PDO $pdo): void {
                $now = gmdate('Y-m-d\\TH:i:s\\Z');
                $st = $pdo->prepare('UPDATE products SET price_usd = ROUND(price_usd * 1.02, 2), compare_at_usd = ROUND(compare_at_usd * 1.02, 2), updated_at = ?');
                $st->execute([$now]);
                if ($st->rowCount() === 0) {
                    return; // yeni kurulum: ürün yok
                }
                // statik site yeniden derlensin
                $v = (int) ($pdo->query("SELECT value FROM meta WHERE key = 'content_version'")->fetchColumn() ?: 0) + 1;
                $up = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
                $up->execute(['content_version', (string) $v]);
                $up->execute(['content_updated_at', $now]);
            },
        ],
        // 5 — Yeni ürünler (2026-11): Thinktool CE EVP902, CE EVP802, TES402 ve "Atölye Ekipmanları" kategorisi;
        // CE EVD satış fiyatı 4.200 USD, açıklaması ve SSS'si yenilenir. İçerik ve görseller: _app/icerik/2026-11-yeni-urunler/
        [
            static function (PDO $pdo): void {
                db_icerik_uygula($pdo, __DIR__ . '/../icerik/2026-11-yeni-urunler');
            },
        ],
    ];
}

/**
 * Şema adımıyla gelen içerik paketini uygular ($dir: urunler.json, gorseller.json, uploads/).
 * Kategoriler eklenir/güncellenir, görseller uploads'a kopyalanıp medyaya kaydedilir, yeni ürünler eklenir ve
 * mevcut ürünler güncellenir. Yalnızca ürünü olan veritabanında çalışır (yeni kurulumda başlangıç içeriği sonra
 * yüklenir); aynı kısa adlı ürün ya da aynı adlı görsel zaten varsa atlanır. Uygulama işlevlerine bağlı değildir:
 * eski bir adım, ileride değişen işlevlerden etkilenmesin diye yalnızca PDO kullanır.
 */
function db_icerik_uygula(PDO $pdo, string $dir): void
{
    $c = json_decode((string) @file_get_contents("$dir/urunler.json"), true);
    $gorseller = json_decode((string) @file_get_contents("$dir/gorseller.json"), true);
    if (!is_array($c) || !is_array($gorseller) || (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
        return;
    }
    $now = gmdate('Y-m-d\\TH:i:s\\Z');
    $q = static function (string $sql, array $params = []) use ($pdo): PDOStatement {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    };
    $json = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    foreach ($c['kategoriler'] ?? [] as $k) {
        $q('INSERT OR IGNORE INTO categories (slug, title, description, sort_order, updated_at) VALUES (?, ?, ?, ?, ?)', [$k['slug'], $k['title'], $k['description'] ?? '', (int) ($k['sortOrder'] ?? 100), $now]);
    }
    foreach ($c['kategoriGuncelle'] ?? [] as $k) {
        $q('UPDATE categories SET description = ?, updated_at = ? WHERE slug = ?', [$k['description'], $now, $k['slug']]);
    }

    // Görseller: dosyalar (ve boyut varyantları) uploads'a kopyalanır; var olan dosyaya dokunulmaz
    $uploads = DATA_DIR . '/uploads';
    if (!is_dir($uploads) && !@mkdir($uploads, 0755, true)) {
        throw new RuntimeException('uploads klasörü oluşturulamadı');
    }
    $medya = [];
    foreach ($gorseller as $key => $m) {
        $base = (string) preg_replace('/\.webp$/', '', $m['file']);
        foreach ([$m['file'], ...array_map(static fn ($w) => "$base-$w.webp", $m['variants'])] as $f) {
            if (!is_file("$uploads/$f")) {
                if (!@copy("$dir/uploads/$f", "$uploads/$f")) {
                    throw new RuntimeException("Görsel kopyalanamadı: $f");
                }
                @chmod("$uploads/$f", 0644);
            }
        }
        $q('INSERT OR IGNORE INTO media (file, alt, width, height, mime, variants, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$m['file'], $c['gorseller'][$key] ?? '', (int) $m['width'], (int) $m['height'], 'image/webp', $json($m['variants']), $now]);
        $medya[$key] = (int) $q('SELECT id FROM media WHERE file = ?', [$m['file']])->fetchColumn();
    }
    $gorsel = static fn (mixed $key): ?int => is_string($key) ? ($medya[$key] ?? null) : null;

    $alanlar = array_flip(['modelName', 'badge', 'tagline', 'summary', 'gallery', 'sku', 'vehicleTypes', 'capabilities', 'keyFigures', 'highlights', 'specs', 'compare', 'boxContents', 'sections', 'descriptionHtml', 'faqs', 'seo']);
    foreach ($c['urunler'] ?? [] as $p) {
        if ($q('SELECT 1 FROM products WHERE slug = ?', [$p['slug']])->fetchColumn()) {
            continue;
        }
        $p['gallery'] = array_values(array_filter(array_map($gorsel, $p['gallery'])));
        $p['seo']['image'] = $gorsel($p['seo']['image'] ?? null);
        foreach ($p['sections'] as $i => $b) {
            if (array_key_exists('image', $b)) {
                $p['sections'][$i]['image'] = $gorsel($b['image']);
            }
        }
        $kategori = $q('SELECT id FROM categories WHERE slug = ?', [$p['kategori'] ?? ''])->fetchColumn();
        $q(
            'INSERT INTO products (slug, title, data, price_usd, compare_at_usd, stock_status, stock_quantity, published, featured, category_id, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, NULL, 1, 0, ?, ?, ?, ?)',
            [$p['slug'], $p['title'], $json(array_intersect_key($p, $alanlar) + ['legacyId' => null]), (float) $p['priceUsd'], $p['stockStatus'] ?? 'in_stock', $kategori === false ? null : (int) $kategori, (int) ($p['sortOrder'] ?? 100), $now, $now],
        );
    }

    // Mevcut ürünler: fiyat, veri alanları (seo birleştirilir) ve başlığı eşleşen tanıtım bölümlerinin metni
    foreach ($c['guncelle'] ?? [] as $g) {
        $row = $q('SELECT id, data, price_usd FROM products WHERE slug = ?', [$g['slug']])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }
        $data = json_decode((string) $row['data'], true) ?: [];
        foreach ($g['veri'] ?? [] as $k => $v) {
            $data[$k] = $k === 'seo' && is_array($data['seo'] ?? null) ? array_replace($data['seo'], $v) : $v;
        }
        foreach ($g['bolumMetinleri'] ?? [] as $b) {
            foreach ($data['sections'] ?? [] as $i => $s) {
                if (($s['type'] ?? '') === 'feature' && ($s['heading'] ?? '') === $b['baslik']) {
                    $data['sections'][$i]['body'] = $b['metin'];
                }
            }
        }
        $q('UPDATE products SET data = ?, price_usd = ?, updated_at = ? WHERE id = ?', [$json($data), (float) ($g['priceUsd'] ?? $row['price_usd']), $now, (int) $row['id']]);
    }

    // Statik vitrin yeni içerikle yeniden derlensin.
    $v = (int) ($pdo->query("SELECT value FROM meta WHERE key = 'content_version'")->fetchColumn() ?: 0) + 1;
    $up = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $up->execute(['content_version', (string) $v]);
    $up->execute(['content_updated_at', $now]);
}
