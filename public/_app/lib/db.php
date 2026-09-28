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
            foreach ($steps[$i] as $sql) {
                $pdo->exec($sql);
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

/** Şema adımları. Yalnızca SONA ekleyin; mevcut adımları değiştirmeyin. */
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
    ];
}
