# Cookie Consent System — Design Spec
Date: 2026-05-31

## Overview

GDPR-compliant cookie consent banner for xtrudertools.com. The site is an Astro static site with React + Tailwind, serving three locales (en, sv, fi). The only third-party tracker is a Meta Pixel currently firing unconditionally — this must be gated behind consent.

## Consent Model

Two categories:
- **Necessary** — always on, no consent required (theme preference cookie `xt-theme`)
- **Marketing** — opt-in, gates the Meta Pixel (Facebook Ads)

Consent state stored in `localStorage` under key `xt-cookie-consent`:
```json
{ "necessary": true, "marketing": true|false, "decided": true }
```

The `decided` flag controls whether the banner is shown. Absent or `false` → show banner. `true` → hide banner and apply stored preferences.

## Files Changed

### New: `src/components/CookieBanner.astro`
- Receives `lang: Lang` prop from Layout
- Bottom-fixed banner, full width, above all content (`z-50`)
- Respects `dark:` Tailwind classes to match site theme
- Contents:
  - Title + description with link to privacy policy (`/privacy-policy` or locale equivalent)
  - Toggle row: "Necessary cookies" — always-on, disabled toggle
  - Toggle row: "Marketing cookies" — opt-in toggle, default off
  - "Accept all" button — sets `marketing: true`, saves, hides banner
  - "Save preferences" button — saves current toggle state, hides banner
- Hidden by default via CSS (`hidden` class); inline script at bottom of page reveals it if `decided !== true`
- All strings sourced from `translations.ts` via the `lang` prop

### Modified: `src/i18n/translations.ts`
New `cookieBanner` key added for all three locales:
```
cookieBanner.title
cookieBanner.description
cookieBanner.privacyLinkText
cookieBanner.necessary.label
cookieBanner.necessary.description
cookieBanner.marketing.label
cookieBanner.marketing.description
cookieBanner.acceptAll
cookieBanner.savePreferences
cookieBanner.footerLink
```

### Modified: `src/components/Footer.astro`
- "Cookie Settings" link added to footer link list
- `onclick` handler: deletes `decided` from `xt-cookie-consent` in localStorage, shows the banner

### Modified: `src/layouts/Layout.astro`
- Meta Pixel block in `<head>` restructured (see Facebook Consent Mode section below)
- `<CookieBanner lang={lang} />` added just before `</body>`

## Facebook Consent Mode Integration

The Meta Pixel block executes in this exact order to ensure no data leaks before consent is evaluated:

1. Load `fbevents.js` SDK
2. `fbq('init', '1915222213150549')`
3. Set consent defaults to denied:
   ```js
   fbq('consent', 'default', {
     ad_storage: 'denied',
     ad_user_data: 'denied',
     ad_personalization: 'denied',
     analytics_storage: 'denied'
   });
   ```
4. Read `xt-cookie-consent` from localStorage — if `marketing: true`, immediately upgrade:
   ```js
   fbq('consent', 'update', {
     ad_storage: 'granted',
     ad_user_data: 'granted',
     ad_personalization: 'granted',
     analytics_storage: 'granted'
   });
   ```
5. `fbq('track', 'PageView')` — fires after consent state is resolved

**Banner interactions (no page reload needed):**
- "Accept all" / "Save preferences" with marketing on → `fbq('consent', 'update', { ...granted })`
- "Save preferences" with marketing off → `fbq('consent', 'update', { ...denied })`
- "Cookie Settings" footer link → resets `decided`, re-shows banner; if user changes from granted to denied, `fbq('consent', 'update', { ...denied })` fires immediately

## Locale Routing for Privacy Policy Link

The banner links to the privacy policy page. The URL is derived from the `lang` prop:
- `en` → `/privacy-policy`
- `sv` → `/sv/privacy-policy`
- `fi` → `/fi/privacy-policy`

## Constraints

- No new npm dependencies
- No React island — pure Astro + vanilla JS inline script
- Banner must not block page render (rendered server-side, revealed client-side via script)
- Consent mode defaults must be set before `fbq('track', 'PageView')` on every page load
