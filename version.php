<?php

declare(strict_types=1);

/**
 * Optional global cache-bust salt.
 *
 * Asset URLs already fingerprint automatically from each file's mtime/size
 * when index.php serves the page. You normally do NOT need to change this.
 *
 * Bump it only if a proxy/CDN is still sticky after a deploy and you need
 * every asset URL to change at once. Example: 20261125-1 → 20261125-2
 */
return '20261125-2';
