<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$zip = preg_replace('/[^0-9]/', '', $_GET['zip'] ?? '');
if (strlen($zip) < 3) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid zip code']);
    exit;
}

function doRequest(string $url, bool $verifySsl): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'XtruderTools/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $errno    = curl_errno($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['response' => $response, 'error' => $error, 'errno' => $errno, 'code' => $code];
}

// Posti Location API v1 — try with SSL, fall back without (handles servers with outdated CA bundles)
$url = 'https://locationservice.posti.com/location'
     . '?types=PARCEL_LOCKER,PICKUP_POINT,POSTOFFICE'
     . '&countryCode=FI'
     . '&zipCode=' . urlencode($zip);

$result = doRequest($url, true);

// If SSL fails, retry without verification
if ($result['error'] && in_array($result['errno'], [CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM, 60, 77, 35])) {
    $result = doRequest($url, false);
}

if ($result['error']) {
    http_response_code(502);
    echo json_encode([
        'error'  => 'curl error (' . $result['errno'] . '): ' . $result['error'],
        'url'    => $url,
    ]);
    exit;
}

if ($result['code'] !== 200) {
    http_response_code(502);
    echo json_encode([
        'error'    => 'Posti returned HTTP ' . $result['code'],
        'url'      => $url,
        'body'     => substr((string)$result['response'], 0, 500),
    ]);
    exit;
}

echo $result['response'];
