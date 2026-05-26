<?php
require __DIR__ . '/../api/config.php';
session_start();

if (empty($_SESSION['admin'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$url = $_GET['url'] ?? '';
if (!$url || !str_starts_with($url, 'https://gateway.posti.fi/')) {
    http_response_code(400);
    exit('Invalid URL');
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . POSTI_API_KEY]);
$pdf     = curl_exec($ch);
$code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($code !== 200) {
    http_response_code(502);
    exit('Posti returned HTTP ' . $code);
}

header('Content-Type: ' . ($ctype ?: 'application/pdf'));
header('Content-Disposition: inline; filename="posti-label.pdf"');
echo $pdf;
