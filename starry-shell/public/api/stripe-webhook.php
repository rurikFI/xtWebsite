<?php
require __DIR__ . '/config.php';
require __DIR__ . '/mailer.php';

// Must read raw body before any output
$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify webhook signature
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', $part, 2) + ['', ''];
        $parts[$k] = $v;
    }
    if (empty($parts['t']) || empty($parts['v1'])) return false;

    $expected = hash_hmac('sha256', $parts['t'] . '.' . $payload, $secret);
    return hash_equals($expected, $parts['v1']);
}

http_response_code(200); // Acknowledge immediately
header('Content-Type: application/json');

if (!STRIPE_WEBHOOK_SECRET) {
    error_log('stripe-webhook: STRIPE_WEBHOOK_SECRET not configured');
    echo json_encode(['error' => 'webhook secret not configured']);
    exit;
}

if (!verifyStripeSignature($rawBody, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

$event = json_decode($rawBody, true);
if (($event['type'] ?? '') !== 'checkout.session.completed') {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

$sessionId = $event['data']['object']['id'] ?? '';
if (!$sessionId) {
    echo json_encode(['error' => 'no session id']);
    exit;
}

// Fetch full session from Stripe (webhook payload omits some fields)
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET . ':');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Stripe-Version: 2026-03-25.dahlia']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    error_log('stripe-webhook: could not fetch session ' . $sessionId);
    echo json_encode(['error' => 'could not fetch session']);
    exit;
}

$session = json_decode($response, true);

if (($session['payment_status'] ?? '') !== 'paid') {
    echo json_encode(['ok' => true, 'skipped' => 'not paid']);
    exit;
}

$meta          = $session['metadata']         ?? [];
$contact       = $session['customer_details'] ?? [];
$shipping      = $session['shipping_details'] ?? [];
$customerEmail = $contact['email']            ?? '';
$customerName  = $shipping['name'] ?? $contact['name'] ?? 'there';
$firstName     = explode(' ', trim($customerName))[0];
$sizes         = $meta['sizes'] ?? '—';
$qty           = (int)($meta['qty'] ?? 1);
$totalCents    = (int)($session['amount_total'] ?? 0);
$total         = '€' . number_format($totalCents / 100, 2);

// Skip if already emailed (e.g. success page beat the webhook)
if (($meta['email_sent'] ?? '') === 'true') {
    echo json_encode(['ok' => true, 'skipped' => 'already sent']);
    exit;
}

if (!$customerEmail) {
    error_log('stripe-webhook: no customer email for session ' . $sessionId);
    echo json_encode(['error' => 'no customer email']);
    exit;
}

$sizesHtml = implode('', array_map(
    fn($s) => '<li style="padding:4px 0;color:#334155;">' . htmlspecialchars(trim($s)) . '</li>',
    explode(',', $sizes)
));

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:40px 16px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;max-width:600px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:#e8490c;padding:32px 40px;text-align:center;">
            <img src="https://xtrudertools.com/logo.png" alt="Xtruder™" style="height:32px;width:auto;display:block;margin:0 auto 10px;" />
            <p style="margin:0;color:rgba(255,255,255,0.85);font-size:13px;text-transform:uppercase;letter-spacing:0.08em;">Innovation in insulation</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:40px 40px 32px;">
            <p style="margin:0 0 20px;font-size:17px;color:#0f172a;line-height:1.6;">Hi {$firstName},</p>
            <p style="margin:0 0 24px;font-size:16px;color:#334155;line-height:1.7;">
              Your order is confirmed and your custom Xtruder kit is on its way into production. We're so happy to have you as a customer.
            </p>

            <!-- Order summary -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
              <tr>
                <td style="background:#f8fafc;padding:16px 20px;border-bottom:1px solid #e2e8f0;">
                  <p style="margin:0;font-size:13px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;">Order Summary</p>
                </td>
              </tr>
              <tr>
                <td style="padding:20px;">
                  <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding-bottom:14px;vertical-align:top;">
                        <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;">Sizes ordered</p>
                        <ul style="margin:0;padding:0 0 0 16px;font-size:14px;">
                          {$sizesHtml}
                        </ul>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding-top:14px;border-top:1px solid #f1f5f9;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                          <tr>
                            <td style="font-size:14px;color:#64748b;padding-bottom:6px;">Quantity</td>
                            <td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding-bottom:6px;">{$qty} Xtruder{$qty > 1 ? 's' : ''}</td>
                          </tr>
                          <tr>
                            <td style="font-size:14px;color:#64748b;padding-bottom:6px;">Shipping</td>
                            <td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding-bottom:6px;">2–4 business days</td>
                          </tr>
                          <tr>
                            <td style="font-size:16px;font-weight:700;color:#0f172a;padding-top:10px;border-top:1px solid #e2e8f0;">Total paid</td>
                            <td style="font-size:16px;font-weight:900;color:#e8490c;text-align:right;padding-top:10px;border-top:1px solid #e2e8f0;">{$total}</td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 20px;font-size:16px;color:#334155;line-height:1.7;">
              Rurik and Patrik are working tirelessly to get this order out to you. 🔧
            </p>
            <p style="margin:0 0 20px;font-size:16px;color:#334155;line-height:1.7;">
              Questions about your order? Hit <strong>Reply</strong> and write to us directly — we read and reply to every email.
            </p>
            <p style="margin:0;font-size:16px;color:#334155;line-height:1.7;">
              With care,<br>
              <strong style="color:#0f172a;">Rurik &amp; Patrik Örtendahl</strong><br>
              <span style="font-size:14px;color:#94a3b8;">Co-founders, Xtruder Tools</span>
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;padding:24px 40px;border-top:1px solid #e2e8f0;text-align:center;">
            <p style="margin:0;font-size:12px;color:#94a3b8;">
              Xtruder Tools AB · Släten, Dalsbruk, Finland 25910<br>
              <a href="https://xtrudertools.com" style="color:#e8490c;text-decoration:none;">xtrudertools.com</a>
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

$sent = send_email($customerEmail, 'Your Xtruder order is confirmed 🎉', $html);

if ($sent) {
    // Mark as emailed so send-order-email.php skips it if success page also fires
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET . ':');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['metadata[email_sent]' => 'true']));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Stripe-Version: 2026-03-25.dahlia']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
} else {
    error_log('stripe-webhook: Resend failed for session ' . $sessionId . ' email ' . $customerEmail);
}

echo json_encode(['ok' => $sent]);
