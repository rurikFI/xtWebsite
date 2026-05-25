<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($body['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email']);
    exit;
}

$timestamp = date('c');
$file = EMAILS_CSV;

if (!file_exists($file)) {
    file_put_contents($file, "email,timestamp\n");
}

file_put_contents($file, "$email,$timestamp\n", FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);
