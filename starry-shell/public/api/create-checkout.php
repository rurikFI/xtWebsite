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
$sizes = array_filter(array_map('trim', $body['sizes'] ?? []), fn($s) => $s !== '');

if (count($sizes) < 1 || count($sizes) > 20) {
    http_response_code(400);
    echo json_encode(['error' => 'Between 1 and 20 Xtruders required']);
    exit;
}

$qty = count($sizes);

// Pricing tiers
function unitPriceCents(int $qty): int {
    if ($qty >= 10) return (int) round(2999 * 0.70);
    if ($qty >= 6)  return (int) round(2999 * 0.80);
    if ($qty >= 3)  return (int) round(2999 * 0.90);
    return 2999;
}

function discountLabel(int $qty): string {
    if ($qty >= 10) return '30% off — best deal';
    if ($qty >= 6)  return '20% off + free shipping';
    if ($qty >= 3)  return '10% off';
    return 'Standard price';
}

$origin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$lang = in_array($body['lang'] ?? '', ['sv', 'fi']) ? $body['lang'] : '';
$prefix = $lang ? "/$lang" : '';
$sizesStr = implode(', ', $sizes);

$countries = ['FI','SE','NO','DK','DE','GB','US','EE','LV','LT','PL','NL','BE','FR','AT','CH','IT','ES','PT'];

$params = [
    'mode'                                                               => 'payment',
    'currency'                                                           => 'eur',
    'automatic_tax[enabled]'                                             => 'true',
    'tax_id_collection[enabled]'                                         => 'true',
    'phone_number_collection[enabled]'                                   => 'true',
    'line_items[0][quantity]'                                            => $qty,
    'line_items[0][price_data][currency]'                                => 'eur',
    'line_items[0][price_data][unit_amount]'                             => unitPriceCents($qty),
    'line_items[0][price_data][product_data][name]'                      => "Xtruder™ Custom Kit — $qty unit" . ($qty > 1 ? 's' : ''),
    'line_items[0][price_data][product_data][description]'               => discountLabel($qty) . ' | Sizes: ' . $sizesStr,
    'metadata[sizes]'                                                    => $sizesStr,
    'metadata[qty]'                                                      => $qty,
    'success_url'                                                        => $origin . $prefix . '/success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'                                                         => $origin . $prefix . '/build-your-kit',
    // Free shipping for orders of 3+ Xtruders, otherwise standard rates
    'shipping_options[0][shipping_rate_data][display_name]'              => $qty >= 6 ? 'Posti Pickup Point (Finland) — Free' : 'Posti Pickup Point (Finland)',
    'shipping_options[0][shipping_rate_data][type]'                      => 'fixed_amount',
    'shipping_options[0][shipping_rate_data][fixed_amount][amount]'      => $qty >= 6 ? 0 : 490,
    'shipping_options[0][shipping_rate_data][fixed_amount][currency]'    => 'eur',
    'shipping_options[0][shipping_rate_data][delivery_estimate][minimum][unit]'  => 'business_day',
    'shipping_options[0][shipping_rate_data][delivery_estimate][minimum][value]' => 2,
    'shipping_options[0][shipping_rate_data][delivery_estimate][maximum][unit]'  => 'business_day',
    'shipping_options[0][shipping_rate_data][delivery_estimate][maximum][value]' => 4,
    'shipping_options[1][shipping_rate_data][display_name]'              => $qty >= 6 ? 'Home Delivery (Finland) — Free' : 'Home Delivery (Finland)',
    'shipping_options[1][shipping_rate_data][type]'                      => 'fixed_amount',
    'shipping_options[1][shipping_rate_data][fixed_amount][amount]'      => $qty >= 6 ? 0 : 690,
    'shipping_options[1][shipping_rate_data][fixed_amount][currency]'    => 'eur',
    'shipping_options[1][shipping_rate_data][delivery_estimate][minimum][unit]'  => 'business_day',
    'shipping_options[1][shipping_rate_data][delivery_estimate][minimum][value]' => 2,
    'shipping_options[1][shipping_rate_data][delivery_estimate][maximum][unit]'  => 'business_day',
    'shipping_options[1][shipping_rate_data][delivery_estimate][maximum][value]' => 4,
    'shipping_options[2][shipping_rate_data][display_name]'              => $qty >= 6 ? 'EU & International — Free' : 'EU & International',
    'shipping_options[2][shipping_rate_data][type]'                      => 'fixed_amount',
    'shipping_options[2][shipping_rate_data][fixed_amount][amount]'      => $qty >= 6 ? 0 : 1490,
    'shipping_options[2][shipping_rate_data][fixed_amount][currency]'    => 'eur',
    'shipping_options[2][shipping_rate_data][delivery_estimate][minimum][unit]'  => 'business_day',
    'shipping_options[2][shipping_rate_data][delivery_estimate][minimum][value]' => 5,
    'shipping_options[2][shipping_rate_data][delivery_estimate][maximum][unit]'  => 'business_day',
    'shipping_options[2][shipping_rate_data][delivery_estimate][maximum][value]' => 10,
];

foreach ($countries as $i => $code) {
    $params["shipping_address_collection[allowed_countries][$i]"] = $code;
}

if (!STRIPE_SECRET) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe key not configured on server']);
    exit;
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET . ':');
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Stripe-Version: 2026-03-25.dahlia']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Network error: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200 || empty($data['url'])) {
    http_response_code(500);
    echo json_encode(['error' => $data['error']['message'] ?? 'Stripe error (HTTP ' . $httpCode . ')']);
    exit;
}

echo json_encode(['url' => $data['url']]);
