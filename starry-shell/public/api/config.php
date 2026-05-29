<?php
function _env(string $key): string {
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
}

// Load secrets.php if present (gitignored, uploaded manually to server)
$_secretsFile = __DIR__ . '/secrets.php';
if (file_exists($_secretsFile)) {
    require $_secretsFile;
}

define('STRIPE_SECRET',     defined('_STRIPE_SECRET') && _STRIPE_SECRET ? _STRIPE_SECRET : _env('STRIPE_SECRET'));
define('STRIPE_PUBLISHABLE', defined('_STRIPE_PUBLISHABLE') && _STRIPE_PUBLISHABLE ? _STRIPE_PUBLISHABLE : _env('STRIPE_PUBLISHABLE'));
define('POSTI_API_KEY',     defined('_POSTI_API_KEY') ? _POSTI_API_KEY : _env('POSTI_API_KEY'));
define('POSTI_CUST_NO',     defined('_POSTI_CUST_NO') ? _POSTI_CUST_NO : _env('POSTI_CUST_NO'));
define('ADMIN_SECRET',      defined('_ADMIN_SECRET')  ? _ADMIN_SECRET  : _env('ADMIN_SECRET'));
define('RESEND_API_KEY',    defined('_RESEND_API_KEY') ? _RESEND_API_KEY : _env('RESEND_API_KEY'));
define('EMAILS_CSV',        __DIR__ . '/../../emails.csv');
