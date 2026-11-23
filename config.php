<?php

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit;
}

return [
    // From Brevo: SMTP & API → API keys. The sender address must be verified in Brevo.
    'brevo_api_key' => '',
    'sender_email' => '',
    'sender_name' => 'ClikEssence',
    // Admin inbox. Each new waitlist role sends one notice here.
    'to_email' => '',
    'to_name' => 'ClikEssence',

    // sqlite, or mysql if this host has no SQLite. Leave db_path empty to use storage/waitlist.sqlite.
    // On InfinityFree, set db_path to a folder outside htdocs when that folder is writable.
    'db_driver' => 'sqlite',
    'db_path' => '',
    'mysql' => [
        'host' => 'localhost',
        'database' => '',
        'username' => '',
        'password' => '',
    ],
];
