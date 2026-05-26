<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$zip = preg_replace('/[^0-9]/', '', $_GET['zip'] ?? '');
if (strlen($zip) < 3) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid zip code']);
    exit;
}

$url = 'https://locationservice.posti.com/location'
     . '?types=PARCEL_LOCKER,PICKUP_POINT,POSTOFFICE'
     . '&countryCode=FI'
     . '&zipCode=' . urlencode($zip);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'XtruderTools/1.0');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$curlCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'curl: ' . $curlErr]);
    exit;
}

if ($curlCode !== 200) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Posti returned HTTP ' . $curlCode,
        'body'  => substr((string)$response, 0, 300),
    ]);
    exit;
}

echo $response;
