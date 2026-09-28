<?php

declare(strict_types=1);

/**
 * İçerik sürümü: ürün, kategori, yazı, sayfa, medya veya herkese açık bir ayar değiştiğinde artar.
 * Yayın deposundaki derleme görevi bu sürümü izler ve değiştiğinde statik siteyi yeniden oluşturur.
 */
function content_touch(): void
{
    $v = (int) (meta_get('content_version') ?? 0) + 1;
    meta_set('content_version', (string) $v);
    meta_set('content_updated_at', now_iso());
}

function content_version(): array
{
    return ['version' => (int) (meta_get('content_version') ?? 0), 'updatedAt' => meta_get('content_updated_at') ?? ''];
}

/* ───────────────────────── Yazılar ve sayfalar ───────────────────────── */

function post_defaults(): array
{
    return ['id' => 0, 'slug' => '', 'title' => '', 'excerpt' => '', 'cover' => null, 'contentHtml' => '', 'publishedAt' => '', 'published' => true, 'seo' => ['title' => '', 'description' => '', 'image' => null], 'createdAt' => '', 'updatedAt' => ''];
}

function post_from_row(array $r): array
{
    $p = array_replace(post_defaults(), json_decode($r['data'], true) ?: []);
    return array_replace($p, [
        'id' => (int) $r['id'],
        'slug' => $r['slug'],
        'title' => $r['title'],
        'published' => (bool) $r['published'],
        'publishedAt' => $r['published_at'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ]);
}

function posts_all(bool $publishedOnly = false): array
{
    return array_map('post_from_row', q_all('SELECT * FROM posts' . ($publishedOnly ? ' WHERE published = 1' : '') . ' ORDER BY published_at DESC, id DESC'));
}

function post_save(array $p, ?int $id = null): int
{
    $p = array_replace(post_defaults(), $p);
    $now = now_iso();
    $slug = unique_slug('posts', slugify($p['slug'] ?: $p['title']), $id);
    $data = json_encode(array_intersect_key($p, array_flip(['excerpt', 'cover', 'contentHtml', 'seo'])), JSON_UNESCAPED_UNICODE);
    $publishedAt = $p['publishedAt'] ?: $now;
    if ($id) {
        q('UPDATE posts SET slug=?, title=?, data=?, published=?, published_at=?, updated_at=? WHERE id=?', [$slug, $p['title'], $data, $p['published'] ? 1 : 0, $publishedAt, $now, $id]);
    } else {
        q('INSERT INTO posts (id, slug, title, data, published, published_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)', [$p['id'] ?: null, $slug, $p['title'], $data, $p['published'] ? 1 : 0, $publishedAt, $p['createdAt'] ?: $now, $p['updatedAt'] ?: $now]);
        $id = (int) db()->lastInsertId();
    }
    content_touch();
    return $id;
}

function page_defaults(): array
{
    return ['id' => 0, 'slug' => '', 'title' => '', 'intro' => '', 'contentHtml' => '', 'published' => true, 'showInFooter' => true, 'sortOrder' => 100, 'seo' => ['title' => '', 'description' => '', 'image' => null], 'createdAt' => '', 'updatedAt' => ''];
}

function page_from_row(array $r): array
{
    $p = array_replace(page_defaults(), json_decode($r['data'], true) ?: []);
    return array_replace($p, [
        'id' => (int) $r['id'],
        'slug' => $r['slug'],
        'title' => $r['title'],
        'published' => (bool) $r['published'],
        'showInFooter' => (bool) $r['show_in_footer'],
        'sortOrder' => (int) $r['sort_order'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ]);
}

function pages_all(bool $publishedOnly = false): array
{
    return array_map('page_from_row', q_all('SELECT * FROM pages' . ($publishedOnly ? ' WHERE published = 1' : '') . ' ORDER BY sort_order, id'));
}

function page_save(array $p, ?int $id = null): int
{
    $p = array_replace(page_defaults(), $p);
    $now = now_iso();
    $slug = unique_slug('pages', slugify($p['slug'] ?: $p['title']), $id);
    $data = json_encode(array_intersect_key($p, array_flip(['intro', 'contentHtml', 'seo'])), JSON_UNESCAPED_UNICODE);
    if ($id) {
        q('UPDATE pages SET slug=?, title=?, data=?, published=?, show_in_footer=?, sort_order=?, updated_at=? WHERE id=?', [$slug, $p['title'], $data, $p['published'] ? 1 : 0, $p['showInFooter'] ? 1 : 0, (int) $p['sortOrder'], $now, $id]);
    } else {
        q('INSERT INTO pages (id, slug, title, data, published, show_in_footer, sort_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)', [$p['id'] ?: null, $slug, $p['title'], $data, $p['published'] ? 1 : 0, $p['showInFooter'] ? 1 : 0, (int) $p['sortOrder'], $p['createdAt'] ?: $now, $p['updatedAt'] ?: $now]);
        $id = (int) db()->lastInsertId();
    }
    content_touch();
    return $id;
}

/** Statik sitede sayfa yollarıyla çakışmaması gereken kısa adlar. */
const RESERVED_SLUGS = ['urunler', 'urun', 'blog', 'karsilastir', 'hakkimizda', 'iletisim', 'sepet', 'odeme', 'siparis', 'siparis-takip', 'api', 'yonetim', 'uploads', 'brand', 'images', 'og', '_next'];

function unique_slug(string $table, string $slug, ?int $id): string
{
    if ($table === 'pages' && in_array($slug, RESERVED_SLUGS, true)) {
        $slug .= '-sayfa';
    }
    $base = $slug;
    for ($i = 2; q_val("SELECT id FROM $table WHERE slug = ? AND id IS NOT ?", [$slug, $id]) !== null; $i++) {
        $slug = "$base-$i";
    }
    return $slug;
}

/* ───────────────────────── Dışa aktarım (statik site derlemesi) ───────────────────────── */

/**
 * Statik sitenin derlendiği içerik. Yalnızca yayındaki içerik ve herkese açık ayarlar bulunur;
 * sipariş, müşteri, kullanıcı ve PayTR bilgileri asla dahil edilmez.
 */
function content_export(): array
{
    return [
        'version' => 1,
        'contentVersion' => content_version()['version'],
        'exportedAt' => now_iso(),
        'settings' => settings_public(),
        'categories' => categories_all(),
        'products' => products_all(true),
        'posts' => posts_all(true),
        'pages' => pages_all(true),
        'media' => media_all(),
    ];
}

/* ───────────────────────── İlk kurulum içeriği ───────────────────────── */

/** snapshot.json biçimindeki içeriği boş veritabanına aktarır. */
function content_import(array $c): array
{
    return tx(function () use ($c): array {
        $now = now_iso();
        foreach ($c['media'] ?? [] as $m) {
            q('INSERT OR IGNORE INTO media (id, file, alt, width, height, mime, variants, created_at) VALUES (?,?,?,?,?,?,?,?)', [
                (int) $m['id'], $m['file'], $m['alt'] ?? '', (int) ($m['width'] ?? 0), (int) ($m['height'] ?? 0), $m['mime'] ?? '', json_encode($m['variants'] ?? []), $now,
            ]);
        }
        foreach ($c['categories'] ?? [] as $cat) {
            q('INSERT OR IGNORE INTO categories (id, slug, title, description, sort_order, updated_at) VALUES (?,?,?,?,?,?)', [
                (int) $cat['id'], $cat['slug'], $cat['title'], $cat['description'] ?? '', (int) ($cat['sortOrder'] ?? 100), $now,
            ]);
        }
        foreach ($c['products'] ?? [] as $p) {
            product_save($p);
        }
        foreach ($c['posts'] ?? [] as $p) {
            post_save($p);
        }
        foreach ($c['pages'] ?? [] as $p) {
            page_save($p);
        }
        if (!empty($c['settings'])) {
            settings_save($c['settings']);
        }
        content_touch();
        return [
            'media' => count($c['media'] ?? []),
            'categories' => count($c['categories'] ?? []),
            'products' => count($c['products'] ?? []),
            'posts' => count($c['posts'] ?? []),
            'pages' => count($c['pages'] ?? []),
        ];
    });
}
