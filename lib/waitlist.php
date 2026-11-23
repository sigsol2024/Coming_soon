<?php

declare(strict_types=1);

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit;
}

final class WaitlistStore
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly string $driver,
        public readonly ?string $sqlitePath,
    ) {
    }
}

function waitlist_open(array $config): WaitlistStore
{
    $driver = strtolower(trim((string) ($config['db_driver'] ?? 'sqlite')));
    $mysql = is_array($config['mysql'] ?? null) ? $config['mysql'] : [];
    $mysqlReady = trim((string) ($mysql['database'] ?? '')) !== ''
        && trim((string) ($mysql['username'] ?? '')) !== '';

    if ($driver === 'mysql' || (! extension_loaded('pdo_sqlite') && $mysqlReady)) {
        if (! extension_loaded('pdo_mysql') || ! $mysqlReady) {
            throw new RuntimeException('Database is not available.');
        }
        $host = trim((string) ($mysql['host'] ?? ''));
        $pdo = new PDO(
            'mysql:host='.($host !== '' ? $host : 'localhost').';dbname='.$mysql['database'].';charset=utf8mb4',
            (string) $mysql['username'],
            (string) ($mysql['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS waitlist (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                role VARCHAR(20) NOT NULL,
                email VARCHAR(160) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                admin_notified_at VARCHAR(40) NULL,
                confirmation_sent_at VARCHAR(40) NULL,
                UNIQUE KEY waitlist_email_role (email, role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        return new WaitlistStore($pdo, 'mysql', null);
    }

    if (! extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Database is not available.');
    }

    $path = trim((string) ($config['db_path'] ?? ''));
    if ($path === '') {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'waitlist.sqlite';
    }
    $dir = dirname($path);
    if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
        throw new RuntimeException('Database is not available.');
    }

    $pdo = new PDO('sqlite:'.$path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    waitlist_private_file($path);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS waitlist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            created_at TEXT NOT NULL,
            admin_notified_at TEXT,
            confirmation_sent_at TEXT,
            UNIQUE(email, role)
        )'
    );

    return new WaitlistStore($pdo, 'sqlite', $path);
}

function waitlist_remember(WaitlistStore $store, string $role, string $email, string $phone): array
{
    $find = $store->pdo->prepare('SELECT * FROM waitlist WHERE email = ? AND role = ?');
    $find->execute([$email, $role]);
    $existing = $find->fetch();
    if (is_array($existing)) {
        return ['row' => waitlist_refresh_phone($store, $existing, $phone), 'created' => false];
    }

    $insert = $store->pdo->prepare(
        'INSERT INTO waitlist (role, email, phone, created_at, admin_notified_at, confirmation_sent_at) VALUES (?, ?, ?, ?, NULL, NULL)'
    );
    $inserted = false;
    try {
        $insert->execute([$role, $email, $phone, gmdate('c')]);
        $inserted = true;
    } catch (PDOException $error) {
        $duplicate = $error->getCode() === '23000' || str_contains($error->getMessage(), 'UNIQUE');
        if (! $duplicate) {
            throw $error;
        }
    }

    $find->execute([$email, $role]);
    $row = $find->fetch();
    if (! is_array($row)) {
        throw new RuntimeException('Database is not available.');
    }
    if (! $inserted) {
        return ['row' => waitlist_refresh_phone($store, $row, $phone), 'created' => false];
    }
    waitlist_backup($store);

    return ['row' => $row, 'created' => true];
}

function waitlist_refresh_phone(WaitlistStore $store, array $row, string $phone): array
{
    if (waitlist_filled($row['admin_notified_at'] ?? null) || (string) $row['phone'] === $phone) {
        return $row;
    }
    $update = $store->pdo->prepare('UPDATE waitlist SET phone = ? WHERE id = ? AND (admin_notified_at IS NULL OR admin_notified_at = \'\')');
    $update->execute([$phone, $row['id']]);
    if ($update->rowCount() > 0) {
        $row['phone'] = $phone;
        waitlist_backup($store);
    }

    return $row;
}

function waitlist_backup(WaitlistStore $store): void
{
    if ($store->driver !== 'sqlite' || $store->sqlitePath === null || ! is_file($store->sqlitePath)) {
        return;
    }
    $backup = dirname($store->sqlitePath).DIRECTORY_SEPARATOR.'waitlist.backup.sqlite';
    $temporary = $backup.'.tmp';
    try {
        if (is_file($temporary)) {
            unlink($temporary);
        }
        if (! copy($store->sqlitePath, $temporary)) {
            return;
        }
        if (is_file($backup)) {
            unlink($backup);
        }
        rename($temporary, $backup);
        waitlist_private_file($backup);
    } catch (Throwable) {
        // The signup is already stored. A missed backup must not undo it.
    }
}

function waitlist_deliver(WaitlistStore $store, array $config, int $id, string $column, array $payload): bool
{
    if (! in_array($column, ['admin_notified_at', 'confirmation_sent_at'], true)) {
        return false;
    }
    $token = gmdate('c').'-'.bin2hex(random_bytes(4));
    $claim = $store->pdo->prepare(
        'UPDATE waitlist SET '.$column.' = ? WHERE id = ? AND ('.$column.' IS NULL OR '.$column.' = \'\')'
    );
    $claim->execute([$token, $id]);
    $look = $store->pdo->prepare('SELECT '.$column.' FROM waitlist WHERE id = ?');
    $look->execute([$id]);
    if ((string) $look->fetchColumn() !== $token) {
        return true;
    }
    if (waitlist_send($config, $payload)) {
        return true;
    }
    $release = $store->pdo->prepare('UPDATE waitlist SET '.$column.' = NULL WHERE id = ?');
    $release->execute([$id]);

    return false;
}

function waitlist_brevo_ready(array $config): bool
{
    return trim((string) ($config['brevo_api_key'] ?? '')) !== ''
        && trim((string) ($config['sender_email'] ?? '')) !== ''
        && trim((string) ($config['to_email'] ?? '')) !== '';
}

function waitlist_notify(WaitlistStore $store, array $config, array $row, string $roleLabel): array
{
    $id = (int) $row['id'];
    $adminSent = waitlist_filled($row['admin_notified_at'] ?? null);
    $confirmationSent = waitlist_filled($row['confirmation_sent_at'] ?? null);

    if ($adminSent && $confirmationSent) {
        return ['already' => true, 'reset' => false, 'message' => "You're already on the list."];
    }

    if (! waitlist_brevo_ready($config)) {
        return ['already' => false, 'reset' => true, 'message' => "You're on the list. When ClikEssence is ready, we'll let you know."];
    }

    if (! $adminSent) {
        $adminSent = waitlist_deliver($store, $config, $id, 'admin_notified_at', waitlist_admin_payload($config, $row, $roleLabel));
    }
    if (! $confirmationSent) {
        $confirmationSent = waitlist_deliver($store, $config, $id, 'confirmation_sent_at', waitlist_confirmation_payload($config, $row, $roleLabel));
    }

    if ($adminSent && $confirmationSent) {
        return ['already' => false, 'reset' => true, 'message' => "You're on the list. When ClikEssence is ready, we'll let you know."];
    }

    return ['already' => false, 'reset' => false, 'message' => "You're on the list. We could not send the email just now, so submit this form again in a little while."];
}

function waitlist_admin_payload(array $config, array $row, string $roleLabel): array
{
    return [
        'sender' => [
            'name' => (string) ($config['sender_name'] ?? 'ClikEssence'),
            'email' => (string) $config['sender_email'],
        ],
        'to' => [[
            'email' => (string) $config['to_email'],
            'name' => (string) ($config['to_name'] ?? 'ClikEssence'),
        ]],
        'replyTo' => [
            'email' => (string) $row['email'],
            'name' => $roleLabel,
        ],
        'subject' => 'ClikEssence waitlist: '.$roleLabel,
        'textContent' => "Role: {$roleLabel}\nEmail: {$row['email']}\nPhone: {$row['phone']}\n",
    ];
}

function waitlist_confirmation_payload(array $config, array $row, string $roleLabel): array
{
    $root = dirname(__DIR__);
    $logo = $root.'/assets/clikessence-logo-color.png';
    $mascot = $root.'/assets/blue-orbit-waving.png';
    $roleLine = $roleLabel === 'Business'
        ? 'You joined as a business.'
        : 'You joined as a creator.';

    $text = "You're on the ClikEssence waitlist.\n\n{$roleLine}\n\nWhen ClikEssence is ready, we'll write to you so you can be among the first to get started.\n\nConnect. Create. Grow.\n";

    $html = '<!doctype html><html lang="en"><body style="margin:0;padding:0;background:#DFF1FF;">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#DFF1FF;padding:32px 12px;font-family:\'Open Sans\',Arial,sans-serif;">'
        .'<tr><td align="center">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:20px;overflow:hidden;">'
        .'<tr><td style="padding:28px 32px 0;background:#ffffff;">'
        .'<img src="cid:logo.png" width="180" alt="ClikEssence" style="display:block;border:0;height:auto;">'
        .'</td></tr>'
        .'<tr><td style="padding:32px;">'
        .'<p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#EF751F;">You\'re on the list</p>'
        .'<h1 style="margin:0 0 16px;font-size:28px;line-height:1.25;color:#061431;">We\'ll tell you when ClikEssence is ready.</h1>'
        .'<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#3d5278;">'.$roleLine.' When ClikEssence is ready, we\'ll write to this address so you can be among the first to get started.</p>'
        .'<p style="margin:0;font-size:16px;line-height:1.7;color:#3d5278;">Nothing else will be sent from this signup. Just the news that it\'s time.</p>'
        .'</td></tr>'
        .'<tr><td align="center" style="padding:0 32px 28px;">'
        .'<img src="cid:mascot.png" width="150" alt="" style="display:block;border:0;height:auto;">'
        .'</td></tr>'
        .'<tr><td style="background:#061431;padding:20px 32px;color:#d5e4f5;font-size:13px;">Connect. Create. Grow.</td></tr>'
        .'</table></td></tr></table></body></html>';

    $payload = [
        'sender' => [
            'name' => (string) ($config['sender_name'] ?? 'ClikEssence'),
            'email' => (string) $config['sender_email'],
        ],
        'to' => [[
            'email' => (string) $row['email'],
            'name' => $roleLabel,
        ]],
        'subject' => "You're on the ClikEssence waitlist",
        'htmlContent' => $html,
        'textContent' => $text,
    ];

    $attachments = [];
    foreach (['logo.png' => $logo, 'mascot.png' => $mascot] as $name => $file) {
        if (is_file($file)) {
            $attachments[] = [
                'name' => $name,
                'content' => base64_encode((string) file_get_contents($file)),
            ];
        }
    }
    if ($attachments !== []) {
        $payload['attachment'] = $attachments;
    }

    return $payload;
}

function waitlist_send(array $config, array $payload): bool
{
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: '.trim((string) $config['brevo_api_key']),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $body !== false && $status >= 200 && $status < 300;
}

function waitlist_filled(mixed $value): bool
{
    return trim((string) $value) !== '';
}

function waitlist_private_file(string $path): void
{
    if (is_file($path)) {
        chmod($path, 0600);
    }
}
