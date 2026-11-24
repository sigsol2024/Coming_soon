<?php

declare(strict_types=1);

/**
 * Public homepage entry (the only index).
 *
 * home.html holds the markup. This file adds cache headers and automatic
 * asset fingerprints so browsers pick up new images/CSS after a deploy.
 * Optional salt in version.php forces a global bust if a CDN is sticky.
 */

$template = __DIR__.'/home.html';
$salt = '';
if (is_file(__DIR__.'/version.php')) {
    $raw = require __DIR__.'/version.php';
    $salt = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $raw) ?? '';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-ClikEssence-Build: '.substr(hash('sha256', (string) (@filemtime($template) ?: 0).'|'.$salt), 0, 12));

$html = is_file($template) ? file_get_contents($template) : false;
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Page unavailable.\n";
    exit;
}

$html = preg_replace_callback(
    '#(assets/[A-Za-z0-9._/-]+\.(?:png|jpe?g|gif|svg|webp|ico|css|js))(?:\?v=[^"\')\s]*)?#',
    static function (array $match) use ($salt): string {
        $relative = $match[1];
        $path = __DIR__.'/'.$relative;
        if (! is_file($path)) {
            return $relative.'?v=missing';
        }

        $fingerprint = substr(hash('sha256', filemtime($path).'|'.filesize($path).'|'.$salt), 0, 12);

        return $relative.'?v='.$fingerprint;
    },
    $html
);

if ($html === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Page unavailable.\n";
    exit;
}

echo str_replace('__ASSET_V__', $salt !== '' ? $salt : '1', $html);
