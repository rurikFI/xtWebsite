<?php
require __DIR__ . '/../api/config.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['secret'])) {
    if (!ADMIN_SECRET) {
        $loginError = 'ADMIN_SECRET env var is not set on the server. Add it in Hostinger → Hosting → PHP Config or .htaccess.';
    } elseif ($_POST['secret'] === ADMIN_SECRET) {
        $_SESSION['admin'] = true;
    } else {
        $loginError = 'Wrong password. Server expects a ' . strlen(ADMIN_SECRET) . '-character value starting with "' . htmlspecialchars(substr(ADMIN_SECRET, 0, 2)) . '".';
    }
}
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: /xt-ops/');
    exit;
}

$authed = !empty($_SESSION['admin']);

function createPostiShipment(array $p): array {
    $shipment = [
        'sender' => [
            'name'     => 'Xtruder Tools Oy',
            'address1' => getenv('POSTI_SENDER_STREET') ?: 'YOUR STREET',
            'zipcode'  => getenv('POSTI_SENDER_ZIP')    ?: '25910',
            'city'     => strtoupper(getenv('POSTI_SENDER_CITY') ?: 'TAALINTEHDAS'),
            'country'  => 'FI',
        ],
        'receiver' => [
            'name'     => $p['name'],
            'address1' => $p['address'],
            'zipcode'  => $p['zip'],
            'city'     => $p['city'],
            'country'  => $p['country'],
        ],
        'senderPartners' => [[
            'id'     => 'POSTI',
            'custNo' => POSTI_CUST_NO,
        ]],
        'service' => ['id' => $p['service']],
    ];

    if ($p['phone'])        $shipment['receiver']['phone']   = $p['phone'];
    if ($p['email'])        $shipment['receiver']['email']   = $p['email'];
    if ($p['order_ref'])    $shipment['senderReference']     = substr($p['order_ref'], 0, 35);
    if ($p['pickup_id'])    $shipment['agent']               = ['quickId' => $p['pickup_id']];

    $payload = [
        'pdfConfig' => ['target1Media' => 'laser-a5', 'target2Media' => 'laser-a4'],
        'shipment'  => $shipment,
        'parcels'   => [[
            'copies'      => max(1, (int)$p['copies']),
            'weight'      => max(0.1, (float)$p['weight']),
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
        return ['error' => 'Posti HTTP ' . $httpCode, 'details' => $data, 'raw' => $response];
    }
    return ['ok' => true, 'data' => $data];
}

$result = null;
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_label'])) {
    if (!POSTI_API_KEY) {
        $result = ['error' => 'POSTI_API_KEY env var not set on server'];
    } else {
        $result = createPostiShipment([
            'name'      => trim($_POST['name']     ?? ''),
            'address'   => trim($_POST['address']  ?? ''),
            'zip'       => trim($_POST['zip']      ?? ''),
            'city'      => trim($_POST['city']     ?? ''),
            'country'   => trim($_POST['country']  ?? 'FI'),
            'phone'     => trim($_POST['phone']    ?? ''),
            'email'     => trim($_POST['email']    ?? ''),
            'copies'    => $_POST['copies']        ?? 1,
            'weight'    => $_POST['weight']        ?? 0.3,
            'pickup_id' => trim($_POST['pickup_id']  ?? ''),
            'order_ref' => trim($_POST['order_ref']  ?? ''),
            'service'   => trim($_POST['service']    ?? '2102'),
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Xtruder Admin — Posti Labels</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 2rem 1rem; }
  .card { background: #1e293b; border: 1px solid rgba(255,255,255,.08); border-radius: 16px; padding: 2rem; max-width: 620px; margin: 0 auto; }
  h1 { font-size: 1.4rem; font-weight: 800; margin-bottom: 1.5rem; color: #fff; display: flex; align-items: center; justify-content: space-between; }
  h2 { font-size: .75rem; font-weight: 700; margin: 1.5rem 0 .5rem; color: #64748b; text-transform: uppercase; letter-spacing: .08em; }
  label { display: block; font-size: .8rem; font-weight: 600; color: #94a3b8; margin-bottom: .25rem; margin-top: .75rem; }
  input, select { width: 100%; background: #0f172a; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: .6rem 1rem; color: #e2e8f0; font-size: .875rem; outline: none; }
  input:focus, select:focus { border-color: rgba(232,73,12,.5); }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
  .btn { background: #e8490c; color: #fff; font-weight: 700; font-size: .9rem; border: none; border-radius: 10px; padding: .75rem 1.5rem; cursor: pointer; margin-top: 1.5rem; width: 100%; }
  .btn:hover { background: #c73d0a; }
  .btn-sm { background: #334155; color: #94a3b8; font-weight: 600; font-size: .78rem; border: none; border-radius: 8px; padding: .4rem .9rem; cursor: pointer; }
  .btn-sm:hover { background: #475569; color: #e2e8f0; }
  .error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); border-radius: 10px; padding: 1rem; color: #fca5a5; font-size: .82rem; margin-top: 1rem; word-break: break-all; white-space: pre-wrap; }
  .success { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.25); border-radius: 10px; padding: 1.25rem; margin-top: 1rem; }
  .success-title { font-weight: 700; color: #4ade80; margin-bottom: .75rem; }
  .success a { color: #4ade80; word-break: break-all; display: block; margin-top: .4rem; font-size: .85rem; }
  .mono { font-family: monospace; background: #0f172a; border-radius: 6px; padding: .15rem .5rem; color: #f0abfc; font-size: .85rem; }
  hr { border: none; border-top: 1px solid rgba(255,255,255,.06); margin: 1.25rem 0; }
  .note { font-size: .72rem; color: #475569; margin-top: .2rem; }
</style>
</head>
<body>

<?php if (!$authed): ?>
<div class="card">
  <h1>🏷️ Xtruder Admin</h1>
  <form method="POST">
    <label>Admin Password</label>
    <input type="password" name="secret" autofocus placeholder="Enter ADMIN_SECRET">
    <?php if (!empty($loginError)): ?>
      <div class="error"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <button class="btn" type="submit">Sign in</button>
  </form>
</div>

<?php else: ?>
<div class="card">
  <h1>🏷️ Posti Labels <form method="POST" style="display:inline"><button class="btn-sm" name="logout" value="1">Sign out</button></form></h1>

  <?php if ($result): ?>
    <?php if (!empty($result['error'])): ?>
      <div class="error">❌ <?= htmlspecialchars($result['error']) ?>
<?php if (!empty($result['details'])): ?><?= htmlspecialchars(json_encode($result['details'], JSON_PRETTY_PRINT)) ?><?php endif; ?>
<?php if (!empty($result['raw']) && empty($result['details'])): ?><?= htmlspecialchars($result['raw']) ?><?php endif; ?></div>
    <?php else: ?>
      <div class="success">
        <div class="success-title">✅ Shipment created</div>
        <?php
          $entries = is_array($result['data']) ? $result['data'] : [];
          $entry   = $entries[0] ?? $entries;
          $parcels = $entry['parcels'] ?? [];
          foreach ($parcels as $pc) {
              echo '<div>Tracking: <span class="mono">' . htmlspecialchars($pc['parcelNo'] ?? '—') . '</span></div>';
          }
          $pdfs = $entry['pdfs'] ?? [];
          foreach ($pdfs as $pdf) {
              $desc = htmlspecialchars($pdf['description'] ?? 'Label');
              $href = htmlspecialchars($pdf['href'] ?? '#');
              echo '<a href="' . $href . '" target="_blank">📄 ' . $desc . '</a>';
          }
          if (empty($pdfs)) {
              echo '<div style="color:#94a3b8;font-size:.82rem;margin-top:.5rem">No PDF links in response — check Posti OmaPosti Pro dashboard.</div>';
          }
        ?>
        <p class="note" style="margin-top:.75rem">PDF links expire after 1 hour. Opening requires your Posti API key as Authorization header if prompted.</p>
      </div>
    <?php endif; ?>
    <hr>
  <?php endif; ?>

  <form method="POST">
    <h2>Recipient</h2>
    <label>Full name *</label>
    <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="Matti Meikäläinen">

    <label>Street address *</label>
    <input type="text" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" placeholder="Mannerheimintie 1">

    <div class="row2">
      <div>
        <label>Zip code *</label>
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
          <?php foreach (['FI'=>'Finland','SE'=>'Sweden','DE'=>'Germany','GB'=>'United Kingdom','US'=>'United States','EE'=>'Estonia','NO'=>'Norway','DK'=>'Denmark'] as $code => $name): ?>
            <option value="<?= $code ?>" <?= (($_POST['country'] ?? 'FI') === $code) ? 'selected' : '' ?>><?= $name ?> (<?= $code ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Phone</label>
        <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+358401234567">
      </div>
    </div>

    <label>Email</label>
    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="customer@example.com">

    <h2>Shipment</h2>
    <div class="row2">
      <div>
        <label>Service</label>
        <select name="service">
          <option value="2102" <?= (($_POST['service'] ?? '2102') === '2102') ? 'selected' : '' ?>>2102 — Express Parcel</option>
          <option value="2461" <?= (($_POST['service'] ?? '') === '2461') ? 'selected' : '' ?>>2461 — Small Parcel</option>
          <option value="2103" <?= (($_POST['service'] ?? '') === '2103') ? 'selected' : '' ?>>2103 — Express Parcel Morning</option>
        </select>
      </div>
      <div>
        <label>Units (copies)</label>
        <input type="number" name="copies" min="1" max="99" value="<?= (int)($_POST['copies'] ?? 1) ?>">
      </div>
    </div>

    <label>Weight kg <span style="color:#475569">(per unit — total = copies × weight)</span></label>
    <input type="number" name="weight" min="0.1" step="0.05" value="<?= number_format((float)($_POST['weight'] ?? 0.3), 2) ?>">

    <label>Pickup point ID <span style="color:#475569">(leave empty for home/address delivery)</span></label>
    <input type="text" name="pickup_id" value="<?= htmlspecialchars($_POST['pickup_id'] ?? '') ?>" placeholder="e.g. 207803200">

    <label>Order reference <span style="color:#475569">(Stripe session ID, order #, etc.)</span></label>
    <input type="text" name="order_ref" value="<?= htmlspecialchars($_POST['order_ref'] ?? '') ?>" placeholder="cs_live_... or #1234">

    <button class="btn" type="submit" name="create_label" value="1">Create Posti Label →</button>
  </form>
</div>
<?php endif; ?>
</body>
</html>
