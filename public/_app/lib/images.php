<?php

declare(strict_types=1);

const IMAGE_VARIANT_WIDTHS = [320, 640, 960, 1280, 1600];
const IMAGE_MAX_WIDTH = 2400;

/**
 * Yüklenen görseli güvenli biçimde yeniden kodlar (webp) ve site için boyut varyantları üretir.
 * Özgün dosya olduğu gibi saklanmaz; yeniden kodlama gömülü zararlı içerikleri temizler.
 */
function image_store_upload(array $file, string $alt = ''): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new UserError('Dosya yüklenemedi.');
    }
    if ($file['size'] > 15 * 1024 * 1024) {
        throw new UserError('Görsel 15 MB\'tan büyük olamaz.');
    }
    return image_store_path($file['tmp_name'], (string) ($file['name'] ?? 'gorsel'), $alt);
}

function image_store_path(string $path, string $originalName, string $alt = ''): array
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new UserError('Yalnızca JPG, PNG, WEBP veya GIF yüklenebilir.');
    }
    $img = @imagecreatefromstring((string) file_get_contents($path));
    if (!$img) {
        throw new UserError('Görsel okunamadı.');
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $img = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
    imagepalettetotruecolor($img);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    if (imagesx($img) > IMAGE_MAX_WIDTH) {
        $img = imagescale($img, IMAGE_MAX_WIDTH, -1, IMG_BICUBIC);
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $base = mb_substr(slugify(pathinfo($originalName, PATHINFO_FILENAME)), 0, 60) . '-' . bin2hex(random_bytes(3));
    $dir = DATA_DIR . '/uploads';
    if (!imagewebp($img, "$dir/$base.webp", 86)) {
        throw new UserError('Görsel kaydedilemedi.');
    }
    @chmod("$dir/$base.webp", 0644); // web sunucusu okuyabilmeli
    $variants = [];
    foreach (IMAGE_VARIANT_WIDTHS as $vw) {
        if ($vw >= $w) {
            continue;
        }
        $v = imagescale($img, $vw, -1, IMG_BICUBIC);
        imagealphablending($v, false);
        imagesavealpha($v, true);
        imagewebp($v, "$dir/$base-$vw.webp", 80);
        @chmod("$dir/$base-$vw.webp", 0644);
        $variants[] = $vw;
    }
    q('INSERT INTO media (file, alt, width, height, mime, variants, created_at) VALUES (?,?,?,?,?,?,?)', ["$base.webp", mb_substr($alt, 0, 200), $w, $h, 'image/webp', json_encode($variants), now_iso()]);
    content_touch();
    return media_by_id((int) db()->lastInsertId());
}

/** Görselin kullanıldığı yerler (silmeden önce kontrol). */
function media_usage(int $id): array
{
    $used = [];
    foreach (products_all() as $p) {
        if (in_array($id, array_map('intval', $p['gallery']), true) || image_in_sections($id, $p)) {
            $used[] = 'Ürün: ' . $p['title'];
        }
    }
    foreach (posts_all() as $p) {
        if ((int) ($p['cover'] ?? 0) === $id || (int) ($p['seo']['image'] ?? 0) === $id) {
            $used[] = 'Yazı: ' . $p['title'];
        }
    }
    return $used;
}

function image_in_sections(int $id, array $p): bool
{
    if ((int) ($p['seo']['image'] ?? 0) === $id) {
        return true;
    }
    foreach ($p['sections'] as $b) {
        if ((int) ($b['image'] ?? 0) === $id || in_array($id, array_map('intval', $b['images'] ?? []), true)) {
            return true;
        }
        foreach ($b['items'] ?? [] as $it) {
            if ((int) ($it['image'] ?? 0) === $id) {
                return true;
            }
        }
    }
    return false;
}

function media_delete(int $id): void
{
    $m = media_by_id($id);
    if (!$m) {
        return;
    }
    if ($usage = media_usage($id)) {
        throw new UserError('Görsel kullanımda: ' . implode(', ', array_slice($usage, 0, 3)));
    }
    $dir = DATA_DIR . '/uploads';
    $base = preg_replace('/\.[a-z0-9]+$/i', '', $m['file']);
    @unlink("$dir/{$m['file']}");
    foreach ($m['variants'] as $w) {
        @unlink("$dir/$base-$w.webp");
    }
    q('DELETE FROM media WHERE id = ?', [$id]);
    content_touch();
}
