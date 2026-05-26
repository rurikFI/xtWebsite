<?php
require __DIR__ . '/../api/config.php';
session_start();

define('ADMIN_PW_HASH', '7ed8d8f3d621006774646fc24fafe0a12bae7bbbb64d784b8c5d55830a8afd05');

// --- Auth ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['secret'])) {
    if (hash_equals(ADMIN_PW_HASH, hash('sha256', $_POST['secret']))) {
        $_SESSION['admin'] = true;
    } else {
        $loginError = 'Wrong password.';
    }
}
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: /xt-ops/');
    exit;
}
$authed = !empty($_SESSION['admin']);

// --- Stripe helpers ---
function stripeGet(string $path): ?array {
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Stripe-Version: 2024-06-20']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function stripePost(string $path, array $params): ?array {
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET . ':');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Stripe-Version: 2024-06-20']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function fetchOrders(): array {
    $data = stripeGet('/checkout/sessions?limit=50&status=complete');
    if (!$data || empty($data['data'])) return [];
    return array_filter($data['data'], fn($s) => ($s['payment_status'] ?? '') === 'paid');
}

// --- Posti label creation ---
function createPostiLabel(array $p): array {
    $shipment = [
        'sender' => [
            'name'     => 'Xtruder Tools Oy',
            'address1' => getenv('POSTI_SENDER_STREET') ?: 'Taalintehdas',
            'zipcode'  => getenv('POSTI_SENDER_ZIP')    ?: '25910',
            'city'     => strtoupper(getenv('POSTI_SENDER_CITY') ?: 'TAALINTEHDAS'),
            'country'  => 'FI',
        ],
        'receiver' => [
            'name'     => $p['name'],
            'address1' => $p['address'],
            'zipcode'  => $p['zip'],
            'city'     => $p['city'],
            'country'  => $p['country'] ?: 'FI',
        ],
        'senderPartners' => [['id' => 'POSTI', 'custNo' => POSTI_CUST_NO]],
        'service'        => ['id' => $p['service'] ?? '2102'],
    ];

    if (!empty($p['phone']))     $shipment['receiver']['phone']  = $p['phone'];
    if (!empty($p['email']))     $shipment['receiver']['email']  = $p['email'];
    if (!empty($p['order_ref'])) $shipment['senderReference']    = substr($p['order_ref'], 0, 35);

    $payload = [
        'pdfConfig' => ['target1Media' => 'laser-a5', 'target2Media' => 'laser-a4'],
        'shipment'  => $shipment,
        'parcels'   => [[
            'copies'      => max(1, (int)($p['copies'] ?? 1)),
            'weight'      => max(0.1, (float)($p['weight'] ?? 0.25)),
            'packageCode' => 'PKT',
        ]],
    ];

    $ch = curl_init('https://gateway.posti.fi/shippingapi/api/v1/shipping/order');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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

    if ($curlErr) return ['error' => 'curl: ' . $curlErr];
    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['error' => 'Posti HTTP ' . $httpCode, 'details' => $data];
    }
    return ['ok' => true, 'data' => $data];
}

// --- Handle POST actions ---
$result     = null;
$activeTab  = $_GET['tab'] ?? 'orders';

if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['secret']) && !isset($_POST['logout'])) {

    if (!POSTI_API_KEY) {
        $result = ['error' => 'POSTI_API_KEY not configured on server'];

    } elseif (isset($_POST['create_from_stripe'])) {
        // One-click from order list
        $sessionId = $_POST['session_id'] ?? '';
        $session   = stripeGet('/checkout/sessions/' . urlencode($sessionId));

        if (!$session) {
            $result = ['error' => 'Could not fetch Stripe session'];
        } else {
            $shipping = $session['shipping_details']   ?? [];
            $contact  = $session['customer_details']   ?? [];
            $addr     = $shipping['address']           ?? [];
            $qty      = (int)($session['metadata']['qty'] ?? 1);

            $result = createPostiLabel([
                'name'      => $shipping['name']       ?? $contact['name'] ?? '',
                'address'   => $addr['line1']          ?? '',
                'zip'       => $addr['postal_code']    ?? '',
                'city'      => $addr['city']           ?? '',
                'country'   => $addr['country']        ?? 'FI',
                'phone'     => $contact['phone']       ?? '',
                'email'     => $contact['email']       ?? '',
                'copies'    => $qty,
                'weight'    => 0.25,
                'order_ref' => $sessionId,
                'service'   => $_POST['service']       ?? '2102',
            ]);

            // Update Stripe metadata with tracking number
            if (!empty($result['ok'])) {
                $entry     = $result['data'][0] ?? $result['data'];
                $parcelNo  = $entry['parcels'][0]['parcelNo'] ?? '';
                if ($parcelNo) {
                    stripePost('/checkout/sessions/' . urlencode($sessionId), [
                        'metadata[posti_parcel_no]' => $parcelNo,
                    ]);
                }
            }
        }
        $activeTab = 'orders';

    } elseif (isset($_POST['create_label'])) {
        // Manual form
        $result = createPostiLabel([
            'name'      => trim($_POST['name']      ?? ''),
            'address'   => trim($_POST['address']   ?? ''),
            'zip'       => trim($_POST['zip']       ?? ''),
            'city'      => trim($_POST['city']      ?? ''),
            'country'   => trim($_POST['country']   ?? 'FI'),
            'phone'     => trim($_POST['phone']     ?? ''),
            'email'     => trim($_POST['email']     ?? ''),
            'copies'    => $_POST['copies']         ?? 1,
            'weight'    => $_POST['weight']         ?? 0.25,
            'order_ref' => trim($_POST['order_ref'] ?? ''),
            'service'   => trim($_POST['service']   ?? '2102'),
        ]);
        $activeTab = 'manual';
    }
}

// --- Fetch orders for display ---
$orders = [];
if ($authed) {
    $orders = fetchOrders();
}

function fmtDate(int $ts): string {
    return date('d M Y H:i', $ts);
}
function fmtEur(int $cents): string {
    return '€' . number_format($cents / 100, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Xtruder Ops</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 1.5rem 1rem; }
.wrap { max-width: 900px; margin: 0 auto; }
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
h1 { font-size: 1.3rem; font-weight: 800; color: #fff; }
.btn-sm { background: #1e293b; color: #94a3b8; font-weight: 600; font-size: .78rem; border: 1px solid rgba(255,255,255,.08); border-radius: 8px; padding: .4rem .9rem; cursor: pointer; }
.btn-sm:hover { background: #334155; color: #e2e8f0; }
.tabs { display: flex; gap: .5rem; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,.07); padding-bottom: .75rem; }
.tab { background: none; border: none; color: #64748b; font-size: .85rem; font-weight: 600; padding: .4rem .9rem; border-radius: 8px; cursor: pointer; }
.tab.active { background: rgba(232,73,12,.15); color: #e8490c; }
.tab:hover:not(.active) { background: rgba(255,255,255,.04); color: #e2e8f0; }

/* Result box */
.result { border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; }
.result.ok  { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.25); }
.result.err { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); }
.result-title { font-weight: 700; margin-bottom: .6rem; }
.result.ok  .result-title { color: #4ade80; }
.result.err .result-title { color: #f87171; }
.result a { color: #4ade80; display: block; margin-top: .4rem; font-size: .85rem; word-break: break-all; }
.mono { font-family: monospace; background: #0f172a; border-radius: 5px; padding: .1rem .4rem; color: #f0abfc; font-size: .85rem; }
.result pre { font-size: .75rem; color: #94a3b8; white-space: pre-wrap; word-break: break-all; margin-top: .5rem; }

/* Orders table */
.orders-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.orders-table th { text-align: left; color: #475569; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; padding: .5rem .75rem; border-bottom: 1px solid rgba(255,255,255,.07); }
.orders-table td { padding: .75rem; border-bottom: 1px solid rgba(255,255,255,.04); vertical-align: top; }
.orders-table tr:last-child td { border-bottom: none; }
.orders-table tr:hover td { background: rgba(255,255,255,.02); }
.badge { display: inline-block; font-size: .7rem; font-weight: 700; border-radius: 5px; padding: .15rem .45rem; }
.badge-done { background: rgba(34,197,94,.12); color: #4ade80; }
.badge-pending { background: rgba(248,163,17,.1); color: #fbbf24; }
.name { font-weight: 600; color: #fff; }
.addr { color: #64748b; font-size: .78rem; margin-top: .15rem; }
.sizes { color: #94a3b8; font-size: .78rem; }
.no-ship { color: #475569; font-style: italic; font-size: .78rem; }

/* Label button */
.label-btn { background: #e8490c; color: #fff; font-weight: 700; font-size: .78rem; border: none; border-radius: 8px; padding: .45rem .9rem; cursor: pointer; white-space: nowrap; }
.label-btn:hover { background: #c73d0a; }
.service-sel { background: #0f172a; border: 1px solid rgba(255,255,255,.1); border-radius: 6px; padding: .3rem .5rem; color: #94a3b8; font-size: .75rem; margin-bottom: .35rem; width: 100%; }

/* Manual form */
.card { background: #1e293b; border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: 1.75rem; }
.section-title { font-size: .72rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .08em; margin: 1.25rem 0 .5rem; }
label { display: block; font-size: .8rem; font-weight: 600; color: #94a3b8; margin-bottom: .2rem; margin-top: .65rem; }
input, select { width: 100%; background: #0f172a; border: 1px solid rgba(255,255,255,.1); border-radius: 9px; padding: .55rem .9rem; color: #e2e8f0; font-size: .875rem; outline: none; }
input:focus, select:focus { border-color: rgba(232,73,12,.5); }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; }
.btn-primary { background: #e8490c; color: #fff; font-weight: 700; font-size: .9rem; border: none; border-radius: 10px; padding: .75rem 1.5rem; cursor: pointer; margin-top: 1.25rem; width: 100%; }
.btn-primary:hover { background: #c73d0a; }
.empty { text-align: center; padding: 3rem 1rem; color: #475569; font-size: .85rem; }

/* Login */
.login-card { max-width: 380px; margin: 4rem auto; background: #1e293b; border: 1px solid rgba(255,255,255,.08); border-radius: 16px; padding: 2rem; }
</style>
</head>
<body>

<?php if (!$authed): ?>
<div class="login-card">
  <h1 style="margin-bottom:1.5rem">🏷️ Xtruder Ops</h1>
  <form method="POST">
    <label>Password</label>
    <input type="password" name="secret" autofocus>
    <?php if (!empty($loginError)): ?>
      <div style="color:#f87171;font-size:.82rem;margin-top:.75rem"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <button class="btn-primary" type="submit">Sign in</button>
  </form>
</div>

<?php else: ?>
<div class="wrap">
  <div class="topbar">
    <h1>🏷️ Xtruder Ops</h1>
    <form method="POST"><button class="btn-sm" name="logout" value="1">Sign out</button></form>
  </div>

  <?php if ($result): ?>
    <?php if (!empty($result['ok'])): ?>
      <?php
        $entry    = $result['data'][0] ?? $result['data'];
        $parcelNo = $entry['parcels'][0]['parcelNo'] ?? '';
        $pdfs     = $entry['pdfs'] ?? [];
      ?>
      <div class="result ok">
        <div class="result-title">✅ Label created</div>
        <?php if ($parcelNo): ?>
          <div>Tracking: <span class="mono"><?= htmlspecialchars($parcelNo) ?></span></div>
        <?php endif; ?>
        <?php foreach ($pdfs as $pdf): ?>
          <a href="<?= htmlspecialchars($pdf['href'] ?? '#') ?>" target="_blank">
            📄 <?= htmlspecialchars($pdf['description'] ?? 'Label') ?>
          </a>
        <?php endforeach; ?>
        <?php if (empty($pdfs)): ?>
          <div style="color:#94a3b8;font-size:.82rem;margin-top:.4rem">No PDF links returned — check OmaPosti Pro dashboard.</div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="result err">
        <div class="result-title">❌ <?= htmlspecialchars($result['error'] ?? 'Error') ?></div>
        <?php if (!empty($result['details'])): ?>
          <pre><?= htmlspecialchars(json_encode($result['details'], JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="tabs">
    <button class="tab <?= $activeTab === 'orders' ? 'active' : '' ?>" onclick="showTab('orders')">Orders</button>
    <button class="tab <?= $activeTab === 'manual' ? 'active' : '' ?>" onclick="showTab('manual')">Manual entry</button>
  </div>

  <!-- ORDERS TAB -->
  <div id="tab-orders" style="display:<?= $activeTab === 'orders' ? 'block' : 'none' ?>">
    <?php if (empty($orders)): ?>
      <div class="empty">No completed orders found in Stripe.</div>
    <?php else: ?>
      <table class="orders-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Customer</th>
            <th>Order</th>
            <th>Total</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $s):
            $shipping  = $s['shipping_details']  ?? [];
            $contact   = $s['customer_details']  ?? [];
            $addr      = $shipping['address']    ?? [];
            $meta      = $s['metadata']          ?? [];
            $parcelNo  = $meta['posti_parcel_no'] ?? '';
            $hasAddr   = !empty($addr['line1'])  && !empty($addr['postal_code']);
            $name      = $shipping['name'] ?? $contact['name'] ?? '—';
            $addrLine  = $hasAddr
              ? $addr['line1'] . ', ' . $addr['postal_code'] . ' ' . $addr['city'] . ' (' . $addr['country'] . ')'
              : null;
          ?>
          <tr>
            <td style="white-space:nowrap;color:#64748b"><?= fmtDate($s['created']) ?></td>
            <td>
              <div class="name"><?= htmlspecialchars($name) ?></div>
              <?php if ($addrLine): ?>
                <div class="addr"><?= htmlspecialchars($addrLine) ?></div>
              <?php else: ?>
                <div class="no-ship">No shipping address</div>
              <?php endif; ?>
              <?php if (!empty($contact['email'])): ?>
                <div class="addr"><?= htmlspecialchars($contact['email']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="sizes"><?= htmlspecialchars($meta['sizes'] ?? '—') ?></div>
              <div class="addr">Qty: <?= (int)($meta['qty'] ?? 1) ?></div>
            </td>
            <td style="white-space:nowrap"><?= fmtEur($s['amount_total'] ?? 0) ?></td>
            <td>
              <?php if ($parcelNo): ?>
                <span class="badge badge-done">Labeled</span>
                <div class="mono" style="margin-top:.3rem;font-size:.72rem"><?= htmlspecialchars($parcelNo) ?></div>
              <?php else: ?>
                <span class="badge badge-pending">Pending</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hasAddr && !$parcelNo): ?>
                <form method="POST">
                  <input type="hidden" name="session_id" value="<?= htmlspecialchars($s['id']) ?>">
                  <select name="service" class="service-sel">
                    <option value="2102">Express Parcel</option>
                    <option value="2461">Small Parcel</option>
                  </select>
                  <button class="label-btn" name="create_from_stripe" value="1">Create Label →</button>
                </form>
              <?php elseif ($parcelNo): ?>
                <span style="color:#475569;font-size:.75rem">Done</span>
              <?php else: ?>
                <span style="color:#475569;font-size:.75rem">Add address manually ↓</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- MANUAL TAB -->
  <div id="tab-manual" style="display:<?= $activeTab === 'manual' ? 'block' : 'none' ?>">
    <div class="card">
      <form method="POST">
        <div class="section-title">Recipient</div>
        <label>Full name *</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="Matti Meikäläinen">
        <label>Street address *</label>
        <input type="text" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" placeholder="Mannerheimintie 1">
        <div class="row2">
          <div>
            <label>Zip *</label>
            <input type="text" name="zip" required value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>" placeholder="00100">
          </div>
          <div>
            <label>City *</label>
            <input type="text" name="city" required value="<?= htmlspecialchars($_POST['city'] ?? '') ?>" placeholder="Helsinki">
          </div>
        </div>
        <div class="row2">
          <div>
            <label>Country</label>
            <select name="country">
              <?php foreach (['FI'=>'Finland','SE'=>'Sweden','NO'=>'Norway','DK'=>'Denmark','DE'=>'Germany','GB'=>'United Kingdom','US'=>'United States','EE'=>'Estonia'] as $c => $n): ?>
                <option value="<?= $c ?>" <?= (($_POST['country'] ?? 'FI') === $c) ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+358401234567">
          </div>
        </div>
        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

        <div class="section-title">Shipment</div>
        <div class="row2">
          <div>
            <label>Service</label>
            <select name="service">
              <option value="2102" <?= (($_POST['service'] ?? '2102') === '2102') ? 'selected' : '' ?>>2102 — Express Parcel</option>
              <option value="2461" <?= (($_POST['service'] ?? '') === '2461') ? 'selected' : '' ?>>2461 — Small Parcel</option>
            </select>
          </div>
          <div>
            <label>Units (copies)</label>
            <input type="number" name="copies" min="1" max="99" value="<?= (int)($_POST['copies'] ?? 1) ?>">
          </div>
        </div>
        <label>Weight kg per unit</label>
        <input type="number" name="weight" min="0.1" step="0.05" value="<?= number_format((float)($_POST['weight'] ?? 0.25), 2) ?>">
        <label>Order reference</label>
        <input type="text" name="order_ref" value="<?= htmlspecialchars($_POST['order_ref'] ?? '') ?>" placeholder="Stripe session ID or order #">
        <button class="btn-primary" type="submit" name="create_label" value="1">Create Posti Label →</button>
      </form>
    </div>
  </div>
</div>

<script>
function showTab(name) {
  document.getElementById('tab-orders').style.display = name === 'orders' ? 'block' : 'none';
  document.getElementById('tab-manual').style.display = name === 'manual' ? 'block' : 'none';
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.textContent.toLowerCase().startsWith(name)));
}
</script>
<?php endif; ?>
</body>
</html>
