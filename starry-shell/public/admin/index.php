<?php
require __DIR__ . '/../api/config.php';

session_start();

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['secret'])) {
    if (ADMIN_SECRET && $_POST['secret'] === ADMIN_SECRET) {
        $_SESSION['admin'] = true;
    } else {
        $loginError = 'Wrong password.';
    }
}
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}

$authed = !empty($_SESSION['admin']);

// Handle label creation
$labelResult = null;
$labelError  = null;
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_label'])) {
    $payload = [
        'name'          => trim($_POST['name']          ?? ''),
        'address'       => trim($_POST['address']       ?? ''),
        'zip'           => trim($_POST['zip']           ?? ''),
        'city'          => trim($_POST['city']          ?? ''),
        'country'       => trim($_POST['country']       ?? 'FI'),
        'phone'         => trim($_POST['phone']         ?? ''),
        'email'         => trim($_POST['email']         ?? ''),
        'copies'        => (int)($_POST['copies']       ?? 1),
        'weight'        => (float)($_POST['weight']     ?? 0.3),
        'pickupPointId' => trim($_POST['pickup_id']     ?? ''),
        'orderRef'      => trim($_POST['order_ref']     ?? ''),
    ];

    $ch = curl_init('http://localhost/api/create-shipment.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Admin-Secret: ' . ADMIN_SECRET,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !isset($data['error'])) {
        $labelResult = $data;
    } else {
        $labelError = $data['error'] ?? 'Unknown error (HTTP ' . $httpCode . ')';
        if (!empty($data['details'])) {
            $labelError .= ': ' . json_encode($data['details']);
        }
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
  .card { background: #1e293b; border: 1px solid rgba(255,255,255,.08); border-radius: 16px; padding: 2rem; max-width: 600px; margin: 0 auto; }
  h1 { font-size: 1.5rem; font-weight: 800; margin-bottom: 1.5rem; color: #fff; }
  h2 { font-size: 1rem; font-weight: 700; margin-bottom: 1rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
  label { display: block; font-size: .8rem; font-weight: 600; color: #94a3b8; margin-bottom: .25rem; margin-top: 1rem; }
  input, select { width: 100%; background: #0f172a; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: .65rem 1rem; color: #e2e8f0; font-size: .9rem; outline: none; }
  input:focus, select:focus { border-color: rgba(232,73,12,.5); }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
  .btn { display: inline-block; background: #e8490c; color: #fff; font-weight: 700; font-size: .9rem; border: none; border-radius: 10px; padding: .75rem 1.5rem; cursor: pointer; margin-top: 1.5rem; width: 100%; }
  .btn:hover { background: #c73d0a; }
  .btn-sm { width: auto; padding: .5rem 1rem; font-size: .8rem; margin-top: 0; float: right; background: #334155; }
  .btn-sm:hover { background: #475569; }
  .error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); border-radius: 10px; padding: 1rem; color: #fca5a5; font-size: .85rem; margin-top: 1rem; word-break: break-all; }
  .success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3); border-radius: 10px; padding: 1.25rem; margin-top: 1rem; }
  .success p { font-size: .85rem; color: #86efac; margin-bottom: .5rem; }
  .success a { color: #4ade80; font-weight: 700; word-break: break-all; }
  .parcel-id { font-family: monospace; background: #0f172a; border-radius: 6px; padding: .2rem .5rem; color: #f0abfc; }
  hr { border: none; border-top: 1px solid rgba(255,255,255,.06); margin: 1.5rem 0; }
  .note { font-size: .75rem; color: #64748b; margin-top: .25rem; }
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
  <h1>🏷️ Create Posti Label
    <form method="POST" style="display:inline">
      <button class="btn btn-sm" name="logout" value="1">Sign out</button>
    </form>
  </h1>

  <?php if ($labelResult): ?>
    <div class="success">
      <p><strong>✅ Shipment created!</strong></p>
      <?php
        $parcels = $labelResult[0]['parcels'] ?? [];
        foreach ($parcels as $p) {
          echo '<p>Tracking ID: <span class="parcel-id">' . htmlspecialchars($p['parcelNo'] ?? '—') . '</span></p>';
        }
        $pdfs = $labelResult[0]['pdfs'] ?? [];
        foreach ($pdfs as $pdf) {
          $desc = htmlspecialchars($pdf['description'] ?? 'Label');
          $href = htmlspecialchars($pdf['href'] ?? '#');
          echo '<p><a href="' . $href . '" target="_blank">📄 Download ' . $desc . '</a></p>';
          echo '<p class="note">Link valid for 1 hour. Opens in new tab — use your API key as Authorization header if prompted.</p>';
        }
      ?>
    </div>
    <hr>
  <?php endif; ?>

  <?php if ($labelError): ?>
    <div class="error">❌ <?= htmlspecialchars($labelError) ?></div>
    <hr>
  <?php endif; ?>

  <form method="POST">
    <h2>Recipient</h2>
    <label>Full name *</label>
    <input type="text" name="name" required placeholder="Matti Meikäläinen" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

    <label>Street address *</label>
    <input type="text" name="address" required placeholder="Mannerheimintie 1" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">

    <div class="row2">
      <div>
        <label>Zip code *</label>
        <input type="text" name="zip" required placeholder="00100" value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>">
      </div>
      <div>
        <label>City *</label>
        <input type="text" name="city" required placeholder="Helsinki" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
      </div>
    </div>

    <div class="row2">
      <div>
        <label>Country</label>
        <select name="country">
          <option value="FI" <?= (($_POST['country'] ?? 'FI') === 'FI') ? 'selected' : '' ?>>Finland (FI)</option>
          <option value="SE" <?= (($_POST['country'] ?? '') === 'SE') ? 'selected' : '' ?>>Sweden (SE)</option>
          <option value="DE" <?= (($_POST['country'] ?? '') === 'DE') ? 'selected' : '' ?>>Germany (DE)</option>
          <option value="GB" <?= (($_POST['country'] ?? '') === 'GB') ? 'selected' : '' ?>>UK (GB)</option>
          <option value="US" <?= (($_POST['country'] ?? '') === 'US') ? 'selected' : '' ?>>USA (US)</option>
        </select>
      </div>
      <div>
        <label>Phone</label>
        <input type="text" name="phone" placeholder="+358401234567" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>
    </div>

    <label>Email</label>
    <input type="email" name="email" placeholder="customer@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

    <hr>
    <h2>Shipment</h2>

    <div class="row2">
      <div>
        <label>Units (copies)</label>
        <input type="number" name="copies" min="1" max="99" value="<?= (int)($_POST['copies'] ?? 1) ?>">
      </div>
      <div>
        <label>Weight per unit (kg)</label>
        <input type="number" name="weight" min="0.1" step="0.1" value="<?= number_format((float)($_POST['weight'] ?? 0.3), 1) ?>">
        <p class="note">Total weight = copies × weight</p>
      </div>
    </div>

    <label>Pickup point ID (from customer's order)</label>
    <input type="text" name="pickup_id" placeholder="e.g. 207803200 — leave empty for home delivery" value="<?= htmlspecialchars($_POST['pickup_id'] ?? '') ?>">

    <label>Order reference (Stripe session ID or order #)</label>
    <input type="text" name="order_ref" placeholder="cs_live_... or #1234" value="<?= htmlspecialchars($_POST['order_ref'] ?? '') ?>">

    <button class="btn" type="submit" name="create_label" value="1">Create Posti Label →</button>
  </form>
</div>
<?php endif; ?>

</body>
</html>
