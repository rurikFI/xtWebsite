<?php
function _env(string $key): string {
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
}
define('STRIPE_SECRET', _env('STRIPE_SECRET'));
define('EMAILS_CSV', __DIR__ . '/../../emails.csv');
