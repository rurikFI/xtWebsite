# Stripe Embedded Checkout + Dynamic Shipping

**Date:** 2026-05-26  
**Status:** Approved

## Goal

Replace the current Stripe hosted-redirect checkout with an embedded Stripe checkout page on the site, and add dynamic shipping rates that adapt to the customer's shipping country.

## Current State

- `build-your-kit.astro` collects kit sizes, posts to `create-checkout.php`, receives a Stripe `url`, redirects customer to Stripe's hosted page.
- No shipping rates are shown anywhere before payment.
- `PostiPickupSelector.astro` exists but is not used.

## New Flow

```
/build-your-kit → click "Proceed to Checkout"
  → navigate to /checkout (on site)
  → page reads xt_kit from localStorage
  → POST to /api/create-checkout.php → receive { clientSecret }
  → Stripe embedded checkout mounts in iframe
  → customer enters shipping address
  → Stripe calls /api/calculate-shipping.php
  → server returns rates based on country
  → customer selects shipping, pays
  → Stripe redirects to /success?session_id=...
```

## Components

### 1. `/api/create-checkout.php` (modified)

**Changes from current:**
- Add `ui_mode=embedded_page`
- Add `permissions[update_shipping_details]=server_only`
- Replace `success_url` + `cancel_url` with a single `return_url` pointing to `/success?session_id={CHECKOUT_SESSION_ID}`
- Add an initial dummy shipping option (`display_name=Calculating…`, `amount=0`) — required by Stripe when using `server_only` update mode
- Return `{ clientSecret }` instead of `{ url }`

**Unchanged:** line item pricing logic, discount tiers, allowed countries, metadata for sizes/qty.

### 2. `/api/calculate-shipping.php` (new)

POST endpoint. Stripe calls this from the embedded checkout when the customer completes the shipping address form.

**Request body (from Stripe client via frontend):**
```json
{
  "checkout_session_id": "cs_xxx",
  "shipping_details": {
    "name": "...",
    "address": { "country": "FI", "city": "...", ... }
  }
}
```

**Logic:**
- Retrieve the Checkout Session from Stripe to validate it exists
- Read `shipping_details.address.country`
- If `FI` → return two rates: Posti Pickup (€4.90) + Home Delivery FI (€6.90)
- All other countries → return one rate: EU & International (€14.90)
- Call Stripe API to update the session with `collected_information.shipping_details` + `shipping_options`
- Return `{ type: "object", value: { succeeded: true } }` on success
- Return `{ type: "error", message: "..." }` on failure

**Shipping rates (inline `shipping_rate_data`):**

| Name | Amount | Delivery estimate |
|---|---|---|
| Posti Pickup Point | €4.90 | 2–4 business days |
| Home Delivery (Finland) | €6.90 | 2–4 business days |
| EU & International | €14.90 | 5–10 business days |

### 3. `/checkout` page — `src/pages/checkout.astro` (new, ×3 langs)

A minimal page that:
1. On load, reads `xt_kit` from localStorage. If empty, redirects to `/build-your-kit`.
2. POSTs to `/api/create-checkout.php` with `{ sizes, lang }` → receives `{ clientSecret }`.
3. Loads `stripe.js` (`https://js.stripe.com/dahlia/stripe.js` per Stripe embedded docs)
4. Calls `stripe.createEmbeddedCheckoutPage({ fetchClientSecret, onShippingDetailsChange })` and mounts to a `#checkout-mount` div.
5. `onShippingDetailsChange` POSTs to `/api/calculate-shipping.php` and resolves with accept/reject.

Shows a small order summary header (qty + product subtotal from localStorage) above the iframe for context.

Localized versions: `src/pages/fi/checkout.astro`, `src/pages/sv/checkout.astro` — same structure, translated strings, `lang` param set accordingly.

### 4. `build-your-kit.astro` (modified, ×3 langs)

**Single change:** The checkout button click handler navigates to `/checkout` (or `/fi/checkout` / `/sv/checkout`) instead of calling the API and redirecting. Kit data remains in localStorage as-is — the checkout page reads it.

No other changes to the kit builder.

## Error Handling

- **Empty kit on `/checkout`**: redirect to `/build-your-kit`
- **Session creation failure**: show inline error, button to go back
- **`calculate-shipping.php` failure**: return `type: "error"` so Stripe shows a message in the iframe
- **Stripe.js load failure**: show fallback message with link back to kit builder

## Reverting to Hosted Redirect

To revert:
1. Restore `create-checkout.php` to return `url` (remove `ui_mode`, `permissions`, dummy rate, swap `return_url` back to `success_url`/`cancel_url`)
2. Restore kit builder checkout button to POST to API and redirect
3. Delete or ignore the `/checkout` pages and `calculate-shipping.php`

Estimated revert time: ~10 minutes.

## Files Changed

| File | Action |
|---|---|
| `public/api/create-checkout.php` | Modify |
| `public/api/calculate-shipping.php` | Create |
| `src/pages/checkout.astro` | Create |
| `src/pages/fi/checkout.astro` | Create |
| `src/pages/sv/checkout.astro` | Create |
| `src/pages/build-your-kit.astro` | Modify (button only) |
| `src/pages/fi/build-your-kit.astro` | Modify (button only) |
| `src/pages/sv/build-your-kit.astro` | Modify (button only) |

## Out of Scope

- Posti pickup point selector (removed from this design)
- Webhook handling / post-payment fulfillment
- Subscription mode
- Apple Pay / Google Pay (disabled by `server_only` shipping per Stripe docs)
