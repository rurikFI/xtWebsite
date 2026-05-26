# Stripe Embedded Checkout + Dynamic Shipping — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Stripe hosted-redirect checkout with an embedded Stripe checkout page on the site, adding dynamic shipping rates (Finnish options for FI addresses, EU & International for everyone else).

**Architecture:** A new `/checkout` page (×3 langs) reads the kit from localStorage, creates a Stripe session in embedded mode, and mounts the Stripe iframe. When the customer enters a shipping address, `calculate-shipping.php` updates the session with the correct rates. The kit builder's checkout button is simplified to a navigation to `/checkout`.

**Tech Stack:** Astro v6, PHP (curl for Stripe API), Stripe Embedded Checkout (stripe.js/dahlia), vanilla TypeScript in Astro `<script>` blocks.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `public/api/config.php` | Modify | Add `STRIPE_PUBLISHABLE` constant |
| `public/api/secrets.php` | Modify (manual) | Add `_STRIPE_PUBLISHABLE` value |
| `public/api/stripe-config.php` | Create | Return publishable key to client |
| `public/api/create-checkout.php` | Modify | Switch to `ui_mode=embedded_page`, return `clientSecret` |
| `public/api/calculate-shipping.php` | Create | Dynamic rate calculator, updates Stripe session |
| `src/pages/checkout.astro` | Create | EN checkout page hosting the Stripe iframe |
| `src/pages/fi/checkout.astro` | Create | FI checkout page |
| `src/pages/sv/checkout.astro` | Create | SV checkout page |
| `src/pages/build-your-kit.astro` | Modify | Checkout button navigates to `/checkout` |
| `src/pages/fi/build-your-kit.astro` | Modify | Checkout button navigates to `/fi/checkout` |
| `src/pages/sv/build-your-kit.astro` | Modify | Checkout button navigates to `/sv/checkout` |

---

## Task 1: Add Stripe publishable key to PHP config

**Files:**
- Modify: `public/api/config.php`
- Modify: `public/api/secrets.php` (manual step — gitignored)
- Create: `public/api/stripe-config.php`

- [ ] **Step 1.1: Add STRIPE_PUBLISHABLE constant to config.php**

Open `public/api/config.php`. After the `STRIPE_SECRET` line, add:

```php
define('STRIPE_PUBLISHABLE', defined('_STRIPE_PUBLISHABLE') && _STRIPE_PUBLISHABLE ? _STRIPE_PUBLISHABLE : _env('STRIPE_PUBLISHABLE'));
```

The file's constant block should now read:

```php
define('STRIPE_SECRET',     defined('_STRIPE_SECRET') && _STRIPE_SECRET ? _STRIPE_SECRET : _env('STRIPE_SECRET'));
define('STRIPE_PUBLISHABLE', defined('_STRIPE_PUBLISHABLE') && _STRIPE_PUBLISHABLE ? _STRIPE_PUBLISHABLE : _env('STRIPE_PUBLISHABLE'));
define('POSTI_API_KEY',     defined('_POSTI_API_KEY') ? _POSTI_API_KEY : _env('POSTI_API_KEY'));
define('POSTI_CUST_NO',     defined('_POSTI_CUST_NO') ? _POSTI_CUST_NO : _env('POSTI_CUST_NO'));
define('ADMIN_SECRET',      defined('_ADMIN_SECRET')  ? _ADMIN_SECRET  : _env('ADMIN_SECRET'));
define('EMAILS_CSV',        __DIR__ . '/../../emails.csv');
```

- [ ] **Step 1.2: Add publishable key to secrets.php**

Open `public/api/secrets.php` (gitignored, lives on server). Add your Stripe publishable key. It follows the same pattern as the secret key already there:

```php
define('_STRIPE_PUBLISHABLE', 'pk_test_XXXXXXXXXXXX');  // replace with your actual pk_test_ or pk_live_ key
```

If you are working locally without `secrets.php`, set `STRIPE_PUBLISHABLE` as an environment variable instead.

- [ ] **Step 1.3: Create stripe-config.php**

Create `public/api/stripe-config.php` with this exact content:

```php
<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (!STRIPE_PUBLISHABLE) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe publishable key not configured']);
    exit;
}

echo json_encode(['publishableKey' => STRIPE_PUBLISHABLE]);
```

- [ ] **Step 1.4: Verify the endpoint**

Start the dev server (`npm run dev` from `starry-shell/`) and visit `http://localhost:4321/api/stripe-config.php` in the browser.

Expected response:
```json
{"publishableKey":"pk_test_..."}
```

- [ ] **Step 1.5: Commit**

```bash
git add public/api/config.php public/api/stripe-config.php
git commit -m "feat: add Stripe publishable key config endpoint"
```

---

## Task 2: Update create-checkout.php for embedded mode

**Files:**
- Modify: `public/api/create-checkout.php`

The current file returns `{ url }` using hosted redirect. We switch it to embedded mode, which returns `{ clientSecret }`.

**Changes summary:**
- Add `ui_mode = embedded_page`
- Add `permissions[update_shipping_details] = server_only`
- Replace `success_url` + `cancel_url` with `return_url`
- Add a dummy initial shipping option (Stripe requires at least one when using `server_only`)
- Return `clientSecret` instead of `url`
- Remove unused `$pickupPointId` / `$pickupLabel` variables

- [ ] **Step 2.1: Replace the $params array**

In `public/api/create-checkout.php`, find and replace the entire `$params = [...]` block.

Old:
```php
$params = [
    'mode'                                          => 'payment',
    'currency'                                      => 'eur',
    'automatic_tax[enabled]'                        => 'true',
    'phone_number_collection[enabled]'              => 'true',
    'line_items[0][quantity]'                       => $qty,
    'line_items[0][price_data][currency]'           => 'eur',
    'line_items[0][price_data][unit_amount]'        => unitPriceCents($qty),
    'line_items[0][price_data][product_data][name]' => "Xtruder™ Custom Kit — $qty unit" . ($qty > 1 ? 's' : ''),
    'line_items[0][price_data][product_data][description]' => discountLabel($qty) . ' | Sizes: ' . $sizesStr,
    'metadata[sizes]'           => $sizesStr,
    'metadata[qty]'             => $qty,
    'success_url'               => $origin . $prefix . '/success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'                => $origin . $prefix . '/cancel',
];
```

New:
```php
$params = [
    'ui_mode'                                                            => 'embedded_page',
    'mode'                                                               => 'payment',
    'currency'                                                           => 'eur',
    'automatic_tax[enabled]'                                             => 'true',
    'phone_number_collection[enabled]'                                   => 'true',
    'permissions[update_shipping_details]'                               => 'server_only',
    'line_items[0][quantity]'                                            => $qty,
    'line_items[0][price_data][currency]'                                => 'eur',
    'line_items[0][price_data][unit_amount]'                             => unitPriceCents($qty),
    'line_items[0][price_data][product_data][name]'                      => "Xtruder™ Custom Kit — $qty unit" . ($qty > 1 ? 's' : ''),
    'line_items[0][price_data][product_data][description]'               => discountLabel($qty) . ' | Sizes: ' . $sizesStr,
    'metadata[sizes]'                                                    => $sizesStr,
    'metadata[qty]'                                                      => $qty,
    'return_url'                                                         => $origin . $prefix . '/success?session_id={CHECKOUT_SESSION_ID}',
    // Placeholder rate — calculate-shipping.php replaces this after customer enters address
    'shipping_options[0][shipping_rate_data][display_name]'              => 'Calculating shipping…',
    'shipping_options[0][shipping_rate_data][type]'                      => 'fixed_amount',
    'shipping_options[0][shipping_rate_data][fixed_amount][amount]'      => 0,
    'shipping_options[0][shipping_rate_data][fixed_amount][currency]'    => 'eur',
];
```

- [ ] **Step 2.2: Remove unused pickup point variables**

Find and delete these two lines (they were never used):
```php
$pickupPointId = trim($body['pickupPointId'] ?? '');
$pickupLabel   = trim($body['pickupLabel']   ?? '');
```

- [ ] **Step 2.3: Update the success check and response**

Find:
```php
if ($httpCode !== 200 || empty($data['url'])) {
    http_response_code(500);
    echo json_encode(['error' => $data['error']['message'] ?? 'Stripe error (HTTP ' . $httpCode . ')']);
    exit;
}

echo json_encode(['url' => $data['url']]);
```

Replace with:
```php
if ($httpCode !== 200 || empty($data['client_secret'])) {
    http_response_code(500);
    echo json_encode(['error' => $data['error']['message'] ?? 'Stripe error (HTTP ' . $httpCode . ')']);
    exit;
}

echo json_encode(['clientSecret' => $data['client_secret']]);
```

- [ ] **Step 2.4: Verify the file compiles (no PHP syntax errors)**

```bash
php -l starry-shell/public/api/create-checkout.php
```

Expected:
```
No syntax errors detected in starry-shell/public/api/create-checkout.php
```

- [ ] **Step 2.5: Commit**

```bash
git add public/api/create-checkout.php
git commit -m "feat: switch Stripe checkout to embedded_page mode with dynamic shipping"
```

---

## Task 3: Create calculate-shipping.php

**Files:**
- Create: `public/api/calculate-shipping.php`

This endpoint is called from the client when the customer finishes entering their shipping address in the Stripe iframe. It retrieves the session, determines the correct rates based on country, and updates the session.

- [ ] **Step 3.1: Create the file**

Create `public/api/calculate-shipping.php` with this exact content:

```php
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
$country         = $address['country'] ?? '';

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
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Stripe-Version: 2024-06-20']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response  = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    echo json_encode(['type' => 'error', 'message' => 'Network error: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    $msg = $data['error']['message'] ?? ('Stripe error (HTTP ' . $httpCode . ')');
    echo json_encode(['type' => 'error', 'message' => $msg]);
    exit;
}

echo json_encode(['type' => 'object', 'value' => ['succeeded' => true]]);
```

- [ ] **Step 3.2: Verify syntax**

```bash
php -l starry-shell/public/api/calculate-shipping.php
```

Expected:
```
No syntax errors detected in starry-shell/public/api/calculate-shipping.php
```

- [ ] **Step 3.3: Commit**

```bash
git add public/api/calculate-shipping.php
git commit -m "feat: add dynamic shipping calculator endpoint"
```

---

## Task 4: Create EN checkout page

**Files:**
- Create: `src/pages/checkout.astro`

This page reads the kit from localStorage, creates the Stripe session, and mounts the embedded Stripe checkout. It loads Stripe.js dynamically (Layout has no head slot). No Navbar — same standalone style as cancel.astro and success.astro.

- [ ] **Step 4.1: Create the file**

Create `src/pages/checkout.astro` with this exact content:

```astro
---
import Layout from '../layouts/Layout.astro';
import '../styles/global.css';
---

<Layout title="Checkout — Xtruder™">
  <main class="min-h-screen bg-slate-50 dark:bg-[#0F172A] transition-colors duration-300 pb-16">
    <div class="max-w-2xl mx-auto px-4 pt-10">

      <!-- Logo -->
      <div class="flex items-center justify-center gap-2 mb-8">
        <img src="/favicon.webp" alt="" class="h-7 w-7 rounded-md object-cover" />
        <img src="/logo.png" alt="Xtruder™" class="h-6 w-auto" />
      </div>

      <!-- Order summary (shown once kit is read) -->
      <div id="order-summary" class="hidden bg-white dark:bg-white/[0.03] border border-slate-200 dark:border-white/10 rounded-2xl px-5 py-4 mb-6">
        <p class="text-slate-400 dark:text-slate-500 text-xs uppercase tracking-wide mb-1">Your order</p>
        <p id="order-details" class="text-slate-800 dark:text-white text-sm font-semibold"></p>
      </div>

      <!-- Error state -->
      <div id="checkout-error" class="hidden bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-5 py-4 mb-6 text-red-700 dark:text-red-300 text-sm">
        <p id="checkout-error-msg"></p>
        <a href="/build-your-kit" class="inline-block mt-3 text-xs font-semibold underline">← Back to kit builder</a>
      </div>

      <!-- Loading state -->
      <div id="checkout-loading" class="text-center py-20 text-slate-400 dark:text-slate-500 text-sm">
        Loading checkout…
      </div>

      <!-- Stripe mounts here -->
      <div id="checkout-mount"></div>

    </div>
  </main>
</Layout>

<script>
  const BASE = 29.99;

  function getUnitPrice(qty: number): number {
    if (qty >= 10) return parseFloat((BASE * 0.70).toFixed(2));
    if (qty >= 6)  return parseFloat((BASE * 0.75).toFixed(2));
    if (qty >= 3)  return parseFloat((BASE * 0.90).toFixed(2));
    return BASE;
  }

  function showError(msg: string) {
    document.getElementById('checkout-loading')!.classList.add('hidden');
    document.getElementById('checkout-error-msg')!.textContent = msg;
    document.getElementById('checkout-error')!.classList.remove('hidden');
  }

  function loadStripeJs(): Promise<void> {
    if ((window as any).Stripe) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/dahlia/stripe.js';
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Stripe.js failed to load'));
      document.head.appendChild(s);
    });
  }

  async function init() {
    // 1. Read kit from localStorage
    let kit: { value: string; unit: string }[] = [];
    try {
      const raw = localStorage.getItem('xt_kit');
      kit = raw ? JSON.parse(raw) : [];
    } catch { kit = []; }

    const sizes = kit.map(r => r.value ? `${r.value} ${r.unit}` : '').filter(Boolean);
    if (!sizes.length) {
      window.location.href = '/build-your-kit';
      return;
    }

    // 2. Show order summary
    const qty = sizes.length;
    const unitPrice = getUnitPrice(qty);
    const subtotal = (unitPrice * qty).toFixed(2);
    document.getElementById('order-details')!.textContent =
      `${qty} Xtruder${qty > 1 ? 's' : ''} — €${subtotal} + shipping`;
    document.getElementById('order-summary')!.classList.remove('hidden');

    // 3. Get Stripe publishable key
    let publishableKey = '';
    try {
      const configRes = await fetch('/api/stripe-config.php');
      const config = await configRes.json();
      publishableKey = config.publishableKey ?? '';
    } catch {
      showError('Could not load payment configuration. Please try again later.');
      return;
    }
    if (!publishableKey) {
      showError('Payment not configured. Please contact support.');
      return;
    }

    // 4. Create Stripe session
    let clientSecret = '';
    try {
      const res = await fetch('/api/create-checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sizes, lang: '' }),
      });
      const data = await res.json();
      if (!data.clientSecret) throw new Error(data.error ?? 'No client secret returned');
      clientSecret = data.clientSecret;
    } catch (e: any) {
      showError(e.message ?? 'Could not start checkout. Please try again.');
      return;
    }

    // 5. Load Stripe.js and mount embedded checkout
    try {
      await loadStripeJs();
    } catch {
      showError('Could not load Stripe. Check your connection and try again.');
      return;
    }

    document.getElementById('checkout-loading')!.classList.add('hidden');

    const stripe = (window as any).Stripe(publishableKey);

    const onShippingDetailsChange = async (event: any) => {
      const { checkoutSessionId, shippingDetails } = event;
      try {
        const res = await fetch('/api/calculate-shipping.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            checkout_session_id: checkoutSessionId,
            shipping_details: shippingDetails,
          }),
        });
        const data = await res.json();
        if (data.type === 'error') {
          return { type: 'reject', errorMessage: data.message };
        }
        return { type: 'accept' };
      } catch {
        return { type: 'reject', errorMessage: 'Could not calculate shipping. Please try again.' };
      }
    };

    const checkout = await stripe.createEmbeddedCheckoutPage({
      fetchClientSecret: () => Promise.resolve(clientSecret),
      onShippingDetailsChange,
    });

    checkout.mount('#checkout-mount');
  }

  init();
</script>
```

- [ ] **Step 4.2: Run the dev server and open the checkout page**

```bash
cd starry-shell && npm run dev
```

Navigate to `http://localhost:4321/checkout` in the browser.

If no kit is in localStorage → you should be redirected to `/build-your-kit`. ✓

Then go to `/build-your-kit`, add at least 1 size, then click the checkout button (which currently still calls the old API — that's fine for now, we'll update it in Task 6). Or navigate directly to `/checkout` after manually setting localStorage:

```javascript
// In browser console:
localStorage.setItem('xt_kit', JSON.stringify([{value:'25',unit:'mm'}]))
```

Then navigate to `/checkout` — you should see the order summary and the Stripe embedded checkout iframe mount. ✓

- [ ] **Step 4.3: Verify shipping rates appear**

In the Stripe embedded checkout iframe, enter a test shipping address:
- **Finland** (FI) → should show: "Posti Pickup Point €4.90" and "Home Delivery (Finland) €6.90"
- **Germany** (DE) → should show: "EU & International €14.90"

Use Stripe test card `4242 4242 4242 4242`, exp `12/34`, CVC `123` to complete a test payment.

After payment → should redirect to `/success?session_id=...` ✓

- [ ] **Step 4.4: Commit**

```bash
git add src/pages/checkout.astro
git commit -m "feat: add EN embedded checkout page with dynamic shipping"
```

---

## Task 5: Create FI and SV checkout pages

**Files:**
- Create: `src/pages/fi/checkout.astro`
- Create: `src/pages/sv/checkout.astro`

Same logic as EN, with translated strings and `lang` set to `'fi'` / `'sv'` in the session creation call.

- [ ] **Step 5.1: Create src/pages/fi/checkout.astro**

```astro
---
import Layout from '../../layouts/Layout.astro';
import '../../styles/global.css';
---

<Layout title="Kassa — Xtruder™" lang="fi">
  <main class="min-h-screen bg-slate-50 dark:bg-[#0F172A] transition-colors duration-300 pb-16">
    <div class="max-w-2xl mx-auto px-4 pt-10">

      <div class="flex items-center justify-center gap-2 mb-8">
        <img src="/favicon.webp" alt="" class="h-7 w-7 rounded-md object-cover" />
        <img src="/logo.png" alt="Xtruder™" class="h-6 w-auto" />
      </div>

      <div id="order-summary" class="hidden bg-white dark:bg-white/[0.03] border border-slate-200 dark:border-white/10 rounded-2xl px-5 py-4 mb-6">
        <p class="text-slate-400 dark:text-slate-500 text-xs uppercase tracking-wide mb-1">Tilauksesi</p>
        <p id="order-details" class="text-slate-800 dark:text-white text-sm font-semibold"></p>
      </div>

      <div id="checkout-error" class="hidden bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-5 py-4 mb-6 text-red-700 dark:text-red-300 text-sm">
        <p id="checkout-error-msg"></p>
        <a href="/fi/build-your-kit" class="inline-block mt-3 text-xs font-semibold underline">← Takaisin pakettisi rakentamiseen</a>
      </div>

      <div id="checkout-loading" class="text-center py-20 text-slate-400 dark:text-slate-500 text-sm">
        Ladataan kassaa…
      </div>

      <div id="checkout-mount"></div>

    </div>
  </main>
</Layout>

<script>
  const BASE = 29.99;

  function getUnitPrice(qty: number): number {
    if (qty >= 10) return parseFloat((BASE * 0.70).toFixed(2));
    if (qty >= 6)  return parseFloat((BASE * 0.75).toFixed(2));
    if (qty >= 3)  return parseFloat((BASE * 0.90).toFixed(2));
    return BASE;
  }

  function showError(msg: string) {
    document.getElementById('checkout-loading')!.classList.add('hidden');
    document.getElementById('checkout-error-msg')!.textContent = msg;
    document.getElementById('checkout-error')!.classList.remove('hidden');
  }

  function loadStripeJs(): Promise<void> {
    if ((window as any).Stripe) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/dahlia/stripe.js';
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Stripe.js failed to load'));
      document.head.appendChild(s);
    });
  }

  async function init() {
    let kit: { value: string; unit: string }[] = [];
    try {
      const raw = localStorage.getItem('xt_kit');
      kit = raw ? JSON.parse(raw) : [];
    } catch { kit = []; }

    const sizes = kit.map(r => r.value ? `${r.value} ${r.unit}` : '').filter(Boolean);
    if (!sizes.length) {
      window.location.href = '/fi/build-your-kit';
      return;
    }

    const qty = sizes.length;
    const unitPrice = getUnitPrice(qty);
    const subtotal = (unitPrice * qty).toFixed(2);
    document.getElementById('order-details')!.textContent =
      `${qty} Xtruder${qty > 1 ? 'a' : ''} — €${subtotal} + toimitus`;
    document.getElementById('order-summary')!.classList.remove('hidden');

    let publishableKey = '';
    try {
      const configRes = await fetch('/api/stripe-config.php');
      const config = await configRes.json();
      publishableKey = config.publishableKey ?? '';
    } catch {
      showError('Maksukonfiguraatiota ei voitu ladata. Yritä myöhemmin uudelleen.');
      return;
    }
    if (!publishableKey) {
      showError('Maksaminen ei ole konfiguroitu. Ota yhteyttä tukeen.');
      return;
    }

    let clientSecret = '';
    try {
      const res = await fetch('/api/create-checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sizes, lang: 'fi' }),
      });
      const data = await res.json();
      if (!data.clientSecret) throw new Error(data.error ?? 'Ei client secretiä');
      clientSecret = data.clientSecret;
    } catch (e: any) {
      showError(e.message ?? 'Kassan aloittaminen epäonnistui. Yritä uudelleen.');
      return;
    }

    try {
      await loadStripeJs();
    } catch {
      showError('Stripen lataaminen epäonnistui. Tarkista yhteys ja yritä uudelleen.');
      return;
    }

    document.getElementById('checkout-loading')!.classList.add('hidden');

    const stripe = (window as any).Stripe(publishableKey);

    const onShippingDetailsChange = async (event: any) => {
      const { checkoutSessionId, shippingDetails } = event;
      try {
        const res = await fetch('/api/calculate-shipping.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            checkout_session_id: checkoutSessionId,
            shipping_details: shippingDetails,
          }),
        });
        const data = await res.json();
        if (data.type === 'error') {
          return { type: 'reject', errorMessage: data.message };
        }
        return { type: 'accept' };
      } catch {
        return { type: 'reject', errorMessage: 'Toimituskuluja ei voitu laskea. Yritä uudelleen.' };
      }
    };

    const checkout = await stripe.createEmbeddedCheckoutPage({
      fetchClientSecret: () => Promise.resolve(clientSecret),
      onShippingDetailsChange,
    });

    checkout.mount('#checkout-mount');
  }

  init();
</script>
```

- [ ] **Step 5.2: Create src/pages/sv/checkout.astro**

```astro
---
import Layout from '../../layouts/Layout.astro';
import '../../styles/global.css';
---

<Layout title="Kassa — Xtruder™" lang="sv">
  <main class="min-h-screen bg-slate-50 dark:bg-[#0F172A] transition-colors duration-300 pb-16">
    <div class="max-w-2xl mx-auto px-4 pt-10">

      <div class="flex items-center justify-center gap-2 mb-8">
        <img src="/favicon.webp" alt="" class="h-7 w-7 rounded-md object-cover" />
        <img src="/logo.png" alt="Xtruder™" class="h-6 w-auto" />
      </div>

      <div id="order-summary" class="hidden bg-white dark:bg-white/[0.03] border border-slate-200 dark:border-white/10 rounded-2xl px-5 py-4 mb-6">
        <p class="text-slate-400 dark:text-slate-500 text-xs uppercase tracking-wide mb-1">Din beställning</p>
        <p id="order-details" class="text-slate-800 dark:text-white text-sm font-semibold"></p>
      </div>

      <div id="checkout-error" class="hidden bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-5 py-4 mb-6 text-red-700 dark:text-red-300 text-sm">
        <p id="checkout-error-msg"></p>
        <a href="/sv/build-your-kit" class="inline-block mt-3 text-xs font-semibold underline">← Tillbaka till kit-byggaren</a>
      </div>

      <div id="checkout-loading" class="text-center py-20 text-slate-400 dark:text-slate-500 text-sm">
        Laddar kassan…
      </div>

      <div id="checkout-mount"></div>

    </div>
  </main>
</Layout>

<script>
  const BASE = 29.99;

  function getUnitPrice(qty: number): number {
    if (qty >= 10) return parseFloat((BASE * 0.70).toFixed(2));
    if (qty >= 6)  return parseFloat((BASE * 0.75).toFixed(2));
    if (qty >= 3)  return parseFloat((BASE * 0.90).toFixed(2));
    return BASE;
  }

  function showError(msg: string) {
    document.getElementById('checkout-loading')!.classList.add('hidden');
    document.getElementById('checkout-error-msg')!.textContent = msg;
    document.getElementById('checkout-error')!.classList.remove('hidden');
  }

  function loadStripeJs(): Promise<void> {
    if ((window as any).Stripe) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/dahlia/stripe.js';
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Stripe.js failed to load'));
      document.head.appendChild(s);
    });
  }

  async function init() {
    let kit: { value: string; unit: string }[] = [];
    try {
      const raw = localStorage.getItem('xt_kit');
      kit = raw ? JSON.parse(raw) : [];
    } catch { kit = []; }

    const sizes = kit.map(r => r.value ? `${r.value} ${r.unit}` : '').filter(Boolean);
    if (!sizes.length) {
      window.location.href = '/sv/build-your-kit';
      return;
    }

    const qty = sizes.length;
    const unitPrice = getUnitPrice(qty);
    const subtotal = (unitPrice * qty).toFixed(2);
    document.getElementById('order-details')!.textContent =
      `${qty} Xtruder${qty > 1 ? 's' : ''} — €${subtotal} + frakt`;
    document.getElementById('order-summary')!.classList.remove('hidden');

    let publishableKey = '';
    try {
      const configRes = await fetch('/api/stripe-config.php');
      const config = await configRes.json();
      publishableKey = config.publishableKey ?? '';
    } catch {
      showError('Kunde inte ladda betalningskonfigurationen. Försök igen senare.');
      return;
    }
    if (!publishableKey) {
      showError('Betalning är inte konfigurerad. Kontakta support.');
      return;
    }

    let clientSecret = '';
    try {
      const res = await fetch('/api/create-checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sizes, lang: 'sv' }),
      });
      const data = await res.json();
      if (!data.clientSecret) throw new Error(data.error ?? 'Ingen client secret returnerades');
      clientSecret = data.clientSecret;
    } catch (e: any) {
      showError(e.message ?? 'Kunde inte starta kassan. Försök igen.');
      return;
    }

    try {
      await loadStripeJs();
    } catch {
      showError('Kunde inte ladda Stripe. Kontrollera din anslutning och försök igen.');
      return;
    }

    document.getElementById('checkout-loading')!.classList.add('hidden');

    const stripe = (window as any).Stripe(publishableKey);

    const onShippingDetailsChange = async (event: any) => {
      const { checkoutSessionId, shippingDetails } = event;
      try {
        const res = await fetch('/api/calculate-shipping.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            checkout_session_id: checkoutSessionId,
            shipping_details: shippingDetails,
          }),
        });
        const data = await res.json();
        if (data.type === 'error') {
          return { type: 'reject', errorMessage: data.message };
        }
        return { type: 'accept' };
      } catch {
        return { type: 'reject', errorMessage: 'Kunde inte beräkna frakt. Försök igen.' };
      }
    };

    const checkout = await stripe.createEmbeddedCheckoutPage({
      fetchClientSecret: () => Promise.resolve(clientSecret),
      onShippingDetailsChange,
    });

    checkout.mount('#checkout-mount');
  }

  init();
</script>
```

- [ ] **Step 5.3: Commit**

```bash
git add src/pages/fi/checkout.astro src/pages/sv/checkout.astro
git commit -m "feat: add FI and SV embedded checkout pages"
```

---

## Task 6: Update EN build-your-kit checkout button

**Files:**
- Modify: `src/pages/build-your-kit.astro`

Replace the async API-calling click handler with a simple navigation to `/checkout`.

- [ ] **Step 6.1: Replace the checkout button click handler**

In `src/pages/build-your-kit.astro`, find:

```javascript
  checkoutBtn.addEventListener('click', async () => {
    const sizes = getSizes();
    if (!sizes.length) return;

    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Redirecting to checkout…';

    try {
      const res = await fetch('/api/create-checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sizes, lang: '' }),
      });
      const data = await res.json();
      if (data.url) {
        window.location.href = data.url;
      } else {
        throw new Error();
      }
    } catch {
      checkoutBtn.disabled = false;
      checkoutBtn.innerHTML = `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Proceed to Checkout`;
      alert('Something went wrong. Please try again.');
    }
  });
```

Replace with:

```javascript
  checkoutBtn.addEventListener('click', () => {
    const sizes = getSizes();
    if (!sizes.length) return;
    saveKit();
    window.location.href = '/checkout';
  });
```

- [ ] **Step 6.2: Verify build-your-kit works end-to-end**

In the browser, go to `/build-your-kit`, add a size, click "Proceed to Checkout". Should navigate to `/checkout` which then creates the Stripe session and mounts the iframe. ✓

- [ ] **Step 6.3: Commit**

```bash
git add src/pages/build-your-kit.astro
git commit -m "feat: simplify EN checkout button to navigate to /checkout"
```

---

## Task 7: Update FI and SV build-your-kit checkout buttons

**Files:**
- Modify: `src/pages/fi/build-your-kit.astro`
- Modify: `src/pages/sv/build-your-kit.astro`

- [ ] **Step 7.1: Update FI build-your-kit**

In `src/pages/fi/build-your-kit.astro`, find:

```javascript
  checkoutBtn.addEventListener('click', async () => {
    const sizes = getSizes();
    if (!sizes.length) return;

    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Siirrytään kassalle…';

    try {
      const res = await fetch('/api/create-checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sizes, lang: 'fi' }),
      });
      if (data.url) { window.location.href = data.url; } else { throw new Error(); }
    } catch {
      checkoutBtn.disabled = false;
      checkoutBtn.innerHTML = `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Siirry kassalle`;
```

Replace with:

```javascript
  checkoutBtn.addEventListener('click', () => {
    const sizes = getSizes();
    if (!sizes.length) return;
    saveKit();
    window.location.href = '/fi/checkout';
  });
```

- [ ] **Step 7.2: Update SV build-your-kit**

In `src/pages/sv/build-your-kit.astro`, find:

```javascript
  checkoutBtn.addEventListener('click', async () => {
    const sizes = getSizes();
    if (!sizes.length) return;

    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Omdirigerar till kassan…';

    try {
      const res = await fetch('/api/create-checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sizes, lang: 'sv' }),
      });
      if (data.url) { window.location.href = data.url; } else { throw new Error(); }
    } catch {
      checkoutBtn.disabled = false;
      checkoutBtn.innerHTML = `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Gå till kassan`;
```

Replace with:

```javascript
  checkoutBtn.addEventListener('click', () => {
    const sizes = getSizes();
    if (!sizes.length) return;
    saveKit();
    window.location.href = '/sv/checkout';
  });
```

- [ ] **Step 7.3: Commit**

```bash
git add src/pages/fi/build-your-kit.astro src/pages/sv/build-your-kit.astro
git commit -m "feat: simplify FI and SV checkout buttons to navigate to /checkout"
```

---

## Task 8: Run build and final verification

- [ ] **Step 8.1: Run production build**

```bash
cd starry-shell && npm run build
```

Expected: build completes with no errors. If TypeScript errors appear in the checkout pages related to `window.Stripe`, they are expected — the `(window as any).Stripe` cast handles this.

- [ ] **Step 8.2: Full end-to-end test (EN)**

1. Go to `/build-your-kit`, add 2 sizes (e.g. "25 mm" and "40 mm")
2. Click "Proceed to Checkout" → lands on `/checkout`
3. Order summary shows "2 Xtruders — €53.98 + shipping" ✓
4. Stripe iframe loads ✓
5. Enter test address in **Finland**: name, Mannerheimintie 1, 00100 Helsinki, FI
6. Shipping options appear: "Posti Pickup Point €4.90" and "Home Delivery (Finland) €6.90" ✓
7. Select one, enter test card `4242 4242 4242 4242`, exp `12/34`, CVC `123`
8. Complete payment → redirected to `/success` ✓

- [ ] **Step 8.3: Test EU address**

Go through the same flow but enter a German address (Berlin, DE).
Shipping option should show only "EU & International €14.90" ✓

- [ ] **Step 8.4: Test empty kit redirect**

Clear localStorage (`localStorage.removeItem('xt_kit')`) and navigate directly to `/checkout`.
Should redirect to `/build-your-kit` immediately ✓

- [ ] **Step 8.5: Final commit**

```bash
git add -A
git commit -m "feat: Stripe embedded checkout + dynamic shipping complete"
```
