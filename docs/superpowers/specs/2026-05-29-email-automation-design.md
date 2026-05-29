# Email Automation & Refund Policy — Design Spec
**Date:** 2026-05-29

## Context

The website has paying customers incoming but no automated emails. The subscribe popup captures emails to a CSV with no follow-up. The success page shows a generic confirmation with no order details. The return policy says 14 days but the trust badge promises 30 days — a legal mismatch. This spec covers fixing all of that.

---

## 1. Resend Setup

**Service:** Resend (resend.com) — transactional email via HTTP API.

**Config:**
- Add `_RESEND_API_KEY` to `public/api/secrets.php`
- Add `RESEND_API_KEY` constant to `public/api/config.php` (same pattern as Stripe/Posti keys)

**Shared mailer:**
- New file: `public/api/mailer.php`
- Single function: `send_email(string $to, string $subject, string $html): bool`
- POSTs to `https://api.resend.com/emails` via curl
- From address: `Xtruder Tools <orders@xtrudertools.com>`
- Returns `true` on success, `false` on failure (never throws)
- All emails in the system go through this one function

---

## 2. Welcome Email (popup signup)

**Trigger:** After `subscribe.php` successfully writes the email to CSV.

**Behavior:**
- Call `send_email()` after the CSV write
- If email fails, subscribe still returns `{ ok: true }` — failure is silent to the user
- No frontend changes

**Content:**
- Warm brand introduction — Finnish archipelago origin story, the problem the Xtruder solves
- "We're glad you found us" tone
- Clear mention: go through with a purchase and receive 30% off
- "Hit REPLY and talk to us — we read and reply to every email"
- Signed personally from Rurik & Patrik

---

## 3. Order Confirmation Email

**Trigger:** Success page JS calls `/api/send-order-email.php?session_id=...` on page load.

**Endpoint — `public/api/send-order-email.php`:**
1. Validate `session_id` param is present
2. Fetch Stripe session via `GET /v1/checkout/sessions/{id}?expand[]=line_items`
3. Check `metadata[email_sent]` — if `"true"`, return `{ ok: true, skipped: true }` (dedup)
4. Extract: customer email, sizes (from metadata), qty, total paid, shipping method + delivery estimate
5. Call `send_email()` with order confirmation HTML
6. On success: PATCH Stripe session to set `metadata[email_sent]=true`
7. Return `{ ok: true, order: { ... } }` with the session data for the success page to use

**Email content:**
- Warm opener, order is confirmed
- Order summary table: sizes, qty, total paid, shipping method, estimated delivery
- Fun closing: "Rurik and Patrik are working tirelessly to get this order out to you"
- "Hit REPLY and talk to us — we read and reply to every email"

**Error handling:**
- If Stripe fetch fails: return 500, success page falls back to generic confirmation
- If email send fails: still return the order data so the success page works; log the failure

---

## 4. Success Page Order Summary

**Trigger:** On page load, call `/api/send-order-email.php?session_id=...` (same endpoint as above).

**Display (on success):**
- "Confirmation sent to [email]"
- Sizes ordered (listed)
- Quantity + total paid
- Shipping method + estimated delivery window

**Fallback:** If the API call fails or returns no data, show the current generic confirmation — customer still sees a sensible page.

**Pages to update:** `success.astro`, `sv/success.astro`, `fi/success.astro`

---

## 5. Refund Policy (rename + update)

**Rename:** `return-policy` → `refund-policy` across all three languages.

**New files:**
- `src/pages/refund-policy.astro`
- `src/pages/sv/refund-policy.astro`
- `src/pages/fi/refund-policy.astro`

**Old URLs:** `/return-policy/`, `/sv/return-policy/`, `/fi/return-policy/` get a redirect to the new URLs (via a redirect page or Astro redirect config).

**Content changes:**
- Hero box: "30-day free refunds" (was "14-day returns")
- Eligibility: 30 days, no questions asked (was 14 days)
- Return shipping: free — Xtruder Tools covers return shipping (was customer-paid)
- All three languages updated consistently

**Link updates:**
- `src/i18n/translations.ts` — footer links for all three locales
- `src/pages/terms-conditions.astro` + SV + FI versions
- `src/pages/shipping-policy.astro` + SV + FI versions

---

## Out of Scope

- Shipping notification emails (post-label creation)
- Email templates in a separate template engine
- Unsubscribe flow for welcome emails
- Webhook-based triggering (deferred — Option A with dedup is sufficient for current volume)
