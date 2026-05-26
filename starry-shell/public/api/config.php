<?php
function _env(string $key): string {
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
}
define('STRIPE_SECRET',   _env('STRIPE_SECRET'));
define('EMAILS_CSV',      __DIR__ . '/../../emails.csv');
define('POSTI_API_KEY',   _env('POSTI_API_KEY'));
define('POSTI_CUST_NO',   _env('POSTI_CUST_NO'));
define('ADMIN_SECRET',    _env('ADMIN_SECRET'));
