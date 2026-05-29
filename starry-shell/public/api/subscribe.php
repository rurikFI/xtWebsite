<?php
require __DIR__ . '/config.php';
require __DIR__ . '/mailer.php';

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

// Send welcome email — failure is silent, subscribe still succeeds
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
            <p style="margin:0 0 20px;font-size:17px;color:#0f172a;line-height:1.6;">Hi there,</p>
            <p style="margin:0 0 20px;font-size:16px;color:#334155;line-height:1.7;">
              It's Rurik and Patrik here — the father and son behind the Xtruder.
            </p>
            <p style="margin:0 0 20px;font-size:16px;color:#334155;line-height:1.7;">
              The story started back in 2012 when Patrik was insulating pipes under our house in the Finnish archipelago. He was completely sure a tool like this already existed — something that would let you slide insulation on evenly, without the mess and the waste. He looked everywhere. It didn't exist.
            </p>
            <p style="margin:0 0 20px;font-size:16px;color:#334155;line-height:1.7;">
              So we built it. The Xtruder is a patented, precision-made tool that applies foam insulation, coatings, and sealants uniformly around pipes, cables, and hoses — in a fraction of the time, with none of the waste.
            </p>
            <p style="margin:0 0 20px;font-size:16px;color:#334155;line-height:1.7;">
              We're really glad you found us.
            </p>

            <!-- Discount box -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0;">
              <tr>
                <td style="background:#fff7ed;border:2px solid #e8490c;border-radius:12px;padding:24px 28px;text-align:center;">
                  <p style="margin:0 0 6px;font-size:32px;font-weight:900;color:#e8490c;letter-spacing:-1px;">30% OFF</p>
                  <p style="margin:0;font-size:14px;color:#7c3aed;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Your first order</p>
                  <p style="margin:10px 0 0;font-size:14px;color:#64748b;line-height:1.6;">
                    When you go through with a purchase, your 30% discount will be automatically applied at checkout.
                  </p>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 20px;font-size:16px;color:#334155;line-height:1.7;">
              If you have any questions, curiosities, or just want to talk shop — hit <strong>Reply</strong> and write to us. We read and reply to every single email.
            </p>
            <p style="margin:0;font-size:16px;color:#334155;line-height:1.7;">
              Talk soon,<br>
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

send_email($email, 'Welcome to Xtruder — here\'s something for you 🎉', $html, 'Xtruder Tools <info@xtrudertools.com>');

echo json_encode(['ok' => true]);
