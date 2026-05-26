<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['type' => 'error', 'message' => 'Method not allowed']);
    exit;
}

if (!STRIPE_SECRET) {
    echo json_encode(['type' => 'error', 'message' => 'Stripe not configured']);
    exit;
}

$body            = json_decode(file_get_contents('php://input'), true);
$sessionId       = trim($body['checkout_session_id'] ?? '');
$shippingDetails = $body['shipping_details'] ?? [];
$address         = $shippingDetails['address'] ?? [];
$country         = strtoupper(trim($address['country'] ?? ''));

if (!$sessionId || !$country) {
    echo json_encode(['type' => 'error', 'message' => 'Missing session ID or country']);
    exit;
}

function buildUpdateParams(string $country, array $shippingDetails): array {
    $address = $shippingDetails['address'] ?? [];

    // Shipping options based on destination country
    if ($country === 'FI') {
        $options = [
            ['display_name' => 'Posti Pickup Point',     'amount' => 490,  'min' => 2, 'max' => 4],
            ['display_name' => 'Home Delivery (Finland)', 'amount' => 690,  'min' => 2, 'max' => 4],
        ];
    } else {
        $options = [
            ['display_name' => 'EU & International',     'amount' => 1490, 'min' => 5, 'max' => 10],
        ];
    }

    $params = [];

    // Shipping options
    foreach ($options as $i => $opt) {
        $p = "shipping_options[$i][shipping_rate_data]";
        $params["$p[display_name]"]                      = $opt['display_name'];
        $params["$p[type]"]                              = 'fixed_amount';
        $params["$p[fixed_amount][amount]"]              = $opt['amount'];
        $params["$p[fixed_amount][currency]"]            = 'eur';
        $params["$p[delivery_estimate][minimum][unit]"]  = 'business_day';
        $params["$p[delivery_estimate][minimum][value]"] = $opt['min'];
        $params["$p[delivery_estimate][maximum][unit]"]  = 'business_day';
        $params["$p[delivery_estimate][maximum][value]"] = $opt['max'];
    }

    // Collected shipping details (required when permissions=server_only)
    $s = 'collected_information[shipping_details]';
    $params["$s[name]"]                    = $shippingDetails['name'] ?? '';
    $params["$s[address][line1]"]          = $address['line1'] ?? '';
    $params["$s[address][line2]"]          = $address['line2'] ?? '';
    $params["$s[address][city]"]           = $address['city'] ?? '';
    $params["$s[address][state]"]          = $address['state'] ?? '';
    $params["$s[address][postal_code]"]    = $address['postal_code'] ?? '';
    $params["$s[address][country]"]        = $country;

    return $params;
}

$params = buildUpdateParams($country, $shippingDetails);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET . ':');
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Stripe-Version: 2025-03-31.basil']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response  = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['type' => 'error', 'message' => 'Network error: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    $msg = $data['error']['message'] ?? ('Stripe error (HTTP ' . $httpCode . ')');
    echo json_encode(['type' => 'error', 'message' => $msg]);
    exit;
}

echo json_encode(['type' => 'object', 'value' => ['succeeded' => true]]);
