<?php

declare(strict_types=1);

/**
 * Serve the Coming Soon page with the cache strategy your team agreed:
 * - HTML: Cache-Control: no-cache (browser may store, but must revalidate)
 * - Assets: long-lived URLs that change automatically when the file changes
 *
 * No build tool required: each assets/... URL gets a fingerprint from
 * filemtime + filesize. Push a changed image/CSS/GIF and visitors fetch it.
 * Optional salt in version.php forces a global bust if a CDN is sticky.
 */

$salt = '';
if (is_file(__DIR__.'/version.php')) {
    $raw = require __DIR__.'/version.php';
    $salt = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $raw) ?? '';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-ClikEssence-Build: '.substr(hash('sha256', (string) filemtime(__DIR__.'/index.html').'|'.$salt), 0, 12));

$html = file_get_contents(__DIR__.'/index.html');
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

// Drop any leftover placeholder if an asset path was missed.
echo str_replace('__ASSET_V__', $salt !== '' ? $salt : '1', $html);
