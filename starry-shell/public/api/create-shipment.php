<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// Verify admin secret
$secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
if (!ADMIN_SECRET || $secret !== ADMIN_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!POSTI_API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'POSTI_API_KEY not configured on server']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

$receiverName    = trim($body['name']          ?? '');
$receiverAddr    = trim($body['address']        ?? '');
$receiverZip     = trim($body['zip']            ?? '');
$receiverCity    = trim($body['city']           ?? '');
$receiverCountry = trim($body['country']        ?? 'FI');
$receiverPhone   = trim($body['phone']          ?? '');
$receiverEmail   = trim($body['email']          ?? '');
$copies          = max(1, (int)($body['copies'] ?? 1));
$weightKg        = max(0.1, (float)($body['weight'] ?? 0.3));
$pickupPointId   = trim($body['pickupPointId']  ?? '');
$orderRef        = trim($body['orderRef']       ?? '');

if (!$receiverName || !$receiverAddr || !$receiverZip || !$receiverCity) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: name, address, zip, city']);
    exit;
}

$shipment = [
    'sender' => [
        'name'     => 'Xtruder Tools Oy',
        'address1' => 'SENDER_STREET_HERE',   // <-- update via POSTI_SENDER_* env vars below
        'zipcode'  => 'SENDER_ZIP_HERE',
        'city'     => 'SENDER_CITY_HERE',
        'country'  => 'FI',
    ],
    'receiver' => [
        'name'     => $receiverName,
        'address1' => $receiverAddr,
        'zipcode'  => $receiverZip,
        'city'     => $receiverCity,
        'country'  => $receiverCountry,
    ],
    'senderPartners' => [[
        'id'     => 'POSTI',
        'custNo' => POSTI_CUST_NO,
    ]],
    'service' => [
        'id' => '2102',  // Express Parcel — change to 2461 for Small Parcel if needed
    ],
];

// Override sender address from optional env vars
$senderStreet = getenv('POSTI_SENDER_STREET') ?: ($_ENV['POSTI_SENDER_STREET'] ?? '');
$senderZip    = getenv('POSTI_SENDER_ZIP')    ?: ($_ENV['POSTI_SENDER_ZIP']    ?? '');
$senderCity   = getenv('POSTI_SENDER_CITY')   ?: ($_ENV['POSTI_SENDER_CITY']  ?? '');
if ($senderStreet) $shipment['sender']['address1'] = $senderStreet;
if ($senderZip)    $shipment['sender']['zipcode']  = $senderZip;
if ($senderCity)   $shipment['sender']['city']     = strtoupper($senderCity);

if ($receiverPhone) $shipment['receiver']['phone']  = $receiverPhone;
if ($receiverEmail) $shipment['receiver']['email']  = $receiverEmail;
if ($orderRef)      $shipment['senderReference']    = substr($orderRef, 0, 35);
if ($pickupPointId) $shipment['agent']              = ['quickId' => $pickupPointId];

$shipment['parcels'] = [[
    'copies'      => $copies,
    'weight'      => $weightKg,
    'packageCode' => 'PKT',
]];

$payload = [
    'pdfConfig' => ['target1Media' => 'laser-a5', 'target2Media' => 'laser-a4'],
    'shipment'  => $shipment,
];

$ch = curl_init('https://gateway.posti.fi/shippingapi/api/v1/shipping/order');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . POSTI_API_KEY,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'Network error: ' . $curlErr]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        'error'   => 'Posti API error (HTTP ' . $httpCode . ')',
        'details' => $data,
    ]);
    exit;
}

echo json_encode($data);
