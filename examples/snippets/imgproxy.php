<?php
/**
 * Сниппет imgproxy — безопасная обёртка для ресайза / WebP / кеша через /img/.
 *
 * Установка: создайте в MODX сниппет с именем `imgproxy` и вставьте этот код.
 * Базовый URL хранилища задаётся системной настройкой `imgproxy_base`
 * (System Settings) или константой ниже.
 *
 * Оптимизирует ТОЛЬКО абсолютные ссылки на ваш CDN/бакет. Любой другой src
 * (локальный /assets, /logo.png, внешний URL, SVG, пустой, уже /img/...)
 * возвращается БЕЗ ИЗМЕНЕНИЙ — поэтому обёртку безопасно применять к любой
 * картинке в шаблоне.
 *
 * Использование (Fenom / pdoTools):
 *   {'imgproxy' | snippet : ['src' => $file.url, 'w' => 640]}
 *   {'imgproxy' | snippet : ['src' => $img, 'w' => 800, 'h' => 600]}
 *   {'imgproxy' | snippet : ['src' => $img, 'w' => 1600, 'q' => 90]}
 *
 * URL-форматы (их обрабатывает nginx + imgproxy):
 *   /img/w:640/<path>            — ресайз по ширине, webp
 *   /img/w:640:h:480/<path>      — ресайз с кропом, webp
 *   /img/w:640:h:480:q:90/<path> — + качество
 *   /img/orig/<path>             — оригинал без обработки
 */

// Публичный базовый URL вашего хранилища (CDN-домен бакета). Можно переопределить
// системной настройкой MODX `imgproxy_base`.
$base = $modx->getOption('imgproxy_base', null, 'https://media.example.com/');

$src = trim((string) $modx->getOption('src', $scriptProperties, ''));
if ($src === '') {
    return '';
}

// Уже обработанный URL — не трогаем (идемпотентность)
if (strpos($src, '/img/') === 0) {
    return $src;
}

// Оптимизируем только абсолютные ссылки на наше хранилище; всё прочее — как есть
if (strpos($src, $base) !== 0) {
    return $src;
}

$path = ltrim(substr($src, strlen($base)), '/');
$path = preg_split('/[?#]/', $path, 2)[0];   // отбрасываем query/fragment
if ($path === '') {
    return $src;
}

// SVG не растрируем
if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
    return $src;
}

$w = (int) $modx->getOption('w', $scriptProperties, 0);
$h = (int) $modx->getOption('h', $scriptProperties, 0);
$q = (int) $modx->getOption('q', $scriptProperties, 0);

if ($w && $h && $q) {
    $opts = "w:{$w}:h:{$h}:q:{$q}";
} elseif ($w && $h) {
    $opts = "w:{$w}:h:{$h}";
} elseif ($w) {
    $opts = "w:{$w}";
} else {
    $opts = 'orig';
}

return "/img/{$opts}/{$path}";
