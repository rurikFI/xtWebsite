<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (!STRIPE_PUBLISHABLE) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe publishable key not configured']);
    exit;
}

echo json_encode(['publishableKey' => STRIPE_PUBLISHABLE]);
