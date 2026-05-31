# Cookie Consent System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a GDPR-compliant cookie consent banner that gates the Meta Pixel behind user consent using Facebook Consent Mode, with a "Cookie Settings" footer link to revisit preferences.

**Architecture:** A pure Astro component (`CookieBanner.astro`) added to the shared `Layout.astro`, storing consent in `localStorage` under `xt-cookie-consent`. The Meta Pixel in `<head>` is restructured to default to denied consent and upgrade only when stored consent is found or the user accepts. All strings come from the existing `translations.ts` i18n system.

**Tech Stack:** Astro, Tailwind CSS v4, vanilla JS inline scripts, Facebook Pixel Consent Mode API, localStorage.

---

### Task 1: Add cookieBanner translations to translations.ts

**Files:**
- Modify: `starry-shell/src/i18n/translations.ts`

- [ ] **Step 1: Add `cookieBanner` key to the `en` locale**

Open `starry-shell/src/i18n/translations.ts`. After the closing brace of `footer: { ... }` in the `en` locale (line ~247, just before the `},` that closes `en:`), add:

```ts
    cookieBanner: {
      title: "Cookie Preferences",
      description: "We use cookies to improve your experience and serve relevant ads. See our",
      privacyLinkText: "Privacy Policy",
      necessary: {
        label: "Necessary",
        description: "Required for the site to function. Always active.",
      },
      marketing: {
        label: "Marketing",
        description: "Used to show you relevant ads on Facebook.",
      },
      acceptAll: "Accept All",
      savePreferences: "Save Preferences",
      footerLink: "Cookie Settings",
    },
```

- [ ] **Step 2: Add `cookieBanner` key to the `sv` locale**

After the closing brace of `footer: { ... }` in the `sv` locale, add:

```ts
    cookieBanner: {
      title: "Cookie-inställningar",
      description: "Vi använder cookies för att förbättra din upplevelse och visa relevanta annonser. Se vår",
      privacyLinkText: "Integritetspolicy",
      necessary: {
        label: "Nödvändiga",
        description: "Krävs för att webbplatsen ska fungera. Alltid aktiv.",
      },
      marketing: {
        label: "Marknadsföring",
        description: "Används för att visa dig relevanta annonser på Facebook.",
      },
      acceptAll: "Acceptera alla",
      savePreferences: "Spara inställningar",
      footerLink: "Cookie-inställningar",
    },
```

- [ ] **Step 3: Add `cookieBanner` key to the `fi` locale**

After the closing brace of `footer: { ... }` in the `fi` locale, add:

```ts
    cookieBanner: {
      title: "Evästeasetukset",
      description: "Käytämme evästeitä käyttökokemuksen parantamiseen ja relevanttien mainosten näyttämiseen. Katso",
      privacyLinkText: "Tietosuojakäytäntö",
      necessary: {
        label: "Välttämättömät",
        description: "Vaaditaan sivuston toimintaan. Aina aktiivinen.",
      },
      marketing: {
        label: "Markkinointi",
        description: "Käytetään relevanttien mainosten näyttämiseen Facebookissa.",
      },
      acceptAll: "Hyväksy kaikki",
      savePreferences: "Tallenna asetukset",
      footerLink: "Evästeasetukset",
    },
```

- [ ] **Step 4: Verify TypeScript compiles**

```bash
cd starry-shell && npx astro check
```

Expected: zero errors. The `Translations` type (`typeof translations.en`) will automatically include the new `cookieBanner` key.

- [ ] **Step 5: Commit**

```bash
git add starry-shell/src/i18n/translations.ts
git commit -m "feat: add cookieBanner translations (en/sv/fi)"
```

---

### Task 2: Create CookieBanner.astro component

**Files:**
- Create: `starry-shell/src/components/CookieBanner.astro`

- [ ] **Step 1: Create the file**

Create `starry-shell/src/components/CookieBanner.astro` with this full content:

```astro
---
import type { Lang } from '../i18n/translations';
import { translations } from '../i18n/translations';

interface Props {
  lang?: Lang;
}
const { lang = 'en' } = Astro.props;
const t = translations[lang].cookieBanner;
const privacyHref = lang === 'en' ? '/privacy-policy/' : `/${lang}/privacy-policy/`;
---

<div
  id="cookie-banner"
  class="hidden fixed bottom-0 left-0 right-0 z-50 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 shadow-2xl"
>
  <div class="max-w-4xl mx-auto px-4 py-5 sm:px-6">
    <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-1">{t.title}</h3>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
      {t.description}{' '}
      <a href={privacyHref} class="underline hover:text-slate-900 dark:hover:text-white transition-colors">
        {t.privacyLinkText}
      </a>.
    </p>

    <div class="space-y-3 mb-5">
      <!-- Necessary cookies (always on) -->
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{t.necessary.label}</p>
          <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{t.necessary.description}</p>
        </div>
        <input
          type="checkbox"
          checked
          disabled
          class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-slate-300 opacity-50 cursor-not-allowed accent-slate-900 dark:accent-white"
        />
      </div>

      <!-- Marketing cookies (opt-in) -->
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{t.marketing.label}</p>
          <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{t.marketing.description}</p>
        </div>
        <input
          id="marketing-toggle"
          type="checkbox"
          class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-slate-300 cursor-pointer accent-slate-900 dark:accent-white"
        />
      </div>
    </div>

    <div class="flex flex-col sm:flex-row gap-2 sm:justify-end">
      <button
        id="cookie-save-btn"
        class="text-sm px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer"
      >
        {t.savePreferences}
      </button>
      <button
        id="cookie-accept-btn"
        class="text-sm px-4 py-2 rounded-lg bg-slate-900 dark:bg-white text-white dark:text-slate-900 hover:bg-slate-700 dark:hover:bg-slate-100 transition-colors font-medium cursor-pointer"
      >
        {t.acceptAll}
      </button>
    </div>
  </div>
</div>

<script is:inline>
  (function () {
    // Show banner if user has not yet decided
    try {
      var stored = JSON.parse(localStorage.getItem('xt-cookie-consent') || '{}');
      if (!stored.decided) {
        document.getElementById('cookie-banner').classList.remove('hidden');
      }
    } catch (e) {}

    function applyConsent(granted) {
      if (granted) {
        fbq('consent', 'update', {
          ad_storage: 'granted',
          ad_user_data: 'granted',
          ad_personalization: 'granted',
          analytics_storage: 'granted',
        });
      } else {
        fbq('consent', 'update', {
          ad_storage: 'denied',
          ad_user_data: 'denied',
          ad_personalization: 'denied',
          analytics_storage: 'denied',
        });
      }
    }

    function hideBanner() {
      document.getElementById('cookie-banner').classList.add('hidden');
    }

    // Accept All
    document.getElementById('cookie-accept-btn').addEventListener('click', function () {
      localStorage.setItem('xt-cookie-consent', JSON.stringify({ necessary: true, marketing: true, decided: true }));
      applyConsent(true);
      hideBanner();
    });

    // Save Preferences
    document.getElementById('cookie-save-btn').addEventListener('click', function () {
      var marketing = document.getElementById('marketing-toggle').checked;
      localStorage.setItem('xt-cookie-consent', JSON.stringify({ necessary: true, marketing: marketing, decided: true }));
      applyConsent(marketing);
      hideBanner();
    });

    // Exposed for the footer "Cookie Settings" button
    window.openCookieSettings = function () {
      try {
        var stored = JSON.parse(localStorage.getItem('xt-cookie-consent') || '{}');
        document.getElementById('marketing-toggle').checked = stored.marketing === true;
        delete stored.decided;
        localStorage.setItem('xt-cookie-consent', JSON.stringify(stored));
      } catch (e) {}
      document.getElementById('cookie-banner').classList.remove('hidden');
    };
  })();
</script>
```

- [ ] **Step 2: Verify build**

```bash
cd starry-shell && npx astro build 2>&1 | tail -20
```

Expected: build completes with no errors (the component is not yet used, so no output about it).

- [ ] **Step 3: Commit**

```bash
git add starry-shell/src/components/CookieBanner.astro
git commit -m "feat: add CookieBanner component with GDPR consent logic"
```

---

### Task 3: Restructure Meta Pixel in Layout.astro and add CookieBanner

**Files:**
- Modify: `starry-shell/src/layouts/Layout.astro`

- [ ] **Step 1: Add CookieBanner import to the frontmatter**

In `Layout.astro`, the frontmatter currently starts at line 1:

```astro
---
import type { Lang } from '../i18n/translations';
```

Add the import for CookieBanner:

```astro
---
import type { Lang } from '../i18n/translations';
import CookieBanner from '../components/CookieBanner.astro';
```

- [ ] **Step 2: Replace the Meta Pixel block in `<head>`**

Find and replace the entire existing Meta Pixel block (currently lines 41–53):

Old block to find (exact):
```astro
    <!-- Meta Pixel -->
    <script is:inline>
      !function(f,b,e,v,n,t,s)
      {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window,document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');
      fbq('init','1915222213150549');
      fbq('track','PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1915222213150549&ev=PageView&noscript=1"/></noscript>
```

Replace with:
```astro
    <!-- Meta Pixel with Facebook Consent Mode -->
    <script is:inline>
      // 1. Load fbevents.js SDK
      !function(f,b,e,v,n,t,s)
      {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window,document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');

      // 2. Initialize pixel
      fbq('init', '1915222213150549');

      // 3. Default all consent signals to denied
      fbq('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
      });

      // 4. Restore full consent for returning visitors who already accepted
      try {
        var _xc = JSON.parse(localStorage.getItem('xt-cookie-consent') || '{}');
        if (_xc.marketing === true) {
          fbq('consent', 'update', {
            ad_storage: 'granted',
            ad_user_data: 'granted',
            ad_personalization: 'granted',
            analytics_storage: 'granted',
          });
        }
      } catch (e) {}

      // 5. Track page view (consent state already set above)
      fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1915222213150549&ev=PageView&noscript=1"/></noscript>
```

- [ ] **Step 3: Add `<CookieBanner>` before `</body>`**

Find the closing `</body>` tag in `Layout.astro`:

```astro
  </body>
</html>
```

Replace with:

```astro
    <CookieBanner lang={lang} />
  </body>
</html>
```

- [ ] **Step 4: Verify build**

```bash
cd starry-shell && npx astro build 2>&1 | tail -20
```

Expected: build completes with no errors.

- [ ] **Step 5: Commit**

```bash
git add starry-shell/src/layouts/Layout.astro
git commit -m "feat: gate Meta Pixel behind Facebook Consent Mode, add CookieBanner to layout"
```

---

### Task 4: Add Cookie Settings link to Footer.astro

**Files:**
- Modify: `starry-shell/src/components/Footer.astro`

- [ ] **Step 1: Add the `cookieBanner` translation import to Footer.astro frontmatter**

The current frontmatter in `Footer.astro` is:

```astro
---
import type { Lang } from '../i18n/translations';
import { translations } from '../i18n/translations';

interface Props {
  lang?: Lang;
}
const { lang = 'en' } = Astro.props;
const t = translations[lang].footer;
---
```

Replace with:

```astro
---
import type { Lang } from '../i18n/translations';
import { translations } from '../i18n/translations';

interface Props {
  lang?: Lang;
}
const { lang = 'en' } = Astro.props;
const t = translations[lang].footer;
const cookieSettingsLabel = translations[lang].cookieBanner.footerLink;
---
```

- [ ] **Step 2: Add the Cookie Settings button after the legalLinks list**

In `Footer.astro`, find the Legal section. The current `<ul>` for legalLinks ends with `</ul>` inside the `<!-- Legal -->` div. After the closing `</ul>`, add the cookie settings button:

Find this exact block:
```astro
      <!-- Legal -->
      <div>
        <h4 class="font-heading font-bold text-slate-400 dark:text-slate-500 text-xs uppercase tracking-widest mb-4">{t.legalLabel}</h4>
        <ul class="space-y-2">
          {t.legalLinks.map(({ href, label }) => (
            <li>
              <a href={href} class="text-slate-400 dark:text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors duration-200 text-sm cursor-pointer">{label}</a>
            </li>
          ))}
        </ul>
      </div>
```

Replace with:
```astro
      <!-- Legal -->
      <div>
        <h4 class="font-heading font-bold text-slate-400 dark:text-slate-500 text-xs uppercase tracking-widest mb-4">{t.legalLabel}</h4>
        <ul class="space-y-2">
          {t.legalLinks.map(({ href, label }) => (
            <li>
              <a href={href} class="text-slate-400 dark:text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors duration-200 text-sm cursor-pointer">{label}</a>
            </li>
          ))}
          <li>
            <button
              onclick="window.openCookieSettings()"
              class="text-slate-400 dark:text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors duration-200 text-sm cursor-pointer text-left"
            >
              {cookieSettingsLabel}
            </button>
          </li>
        </ul>
      </div>
```

- [ ] **Step 3: Verify build**

```bash
cd starry-shell && npx astro build 2>&1 | tail -20
```

Expected: build completes with no errors.

- [ ] **Step 4: Manual smoke test**

```bash
cd starry-shell && npx astro preview
```

Open http://localhost:4321 in a browser.

Check all of the following:
1. Cookie banner appears at bottom of page on first visit (localStorage empty)
2. "Accept All" button hides the banner and writes `{ necessary: true, marketing: true, decided: true }` to `localStorage['xt-cookie-consent']`
3. Refreshing after accepting: banner does NOT appear again
4. "Cookie Settings" link in footer reopens the banner
5. "Save Preferences" with marketing unchecked saves `{ marketing: false, decided: true }`
6. After rejecting: open browser DevTools → Network — verify no Facebook pixel events beyond the initial anonymous Consent Mode signal
7. Open `/sv/` and `/fi/` — verify banner text appears in Swedish and Finnish respectively
8. Toggle dark mode — verify banner colors invert correctly

- [ ] **Step 5: Commit**

```bash
git add starry-shell/src/components/Footer.astro
git commit -m "feat: add Cookie Settings link to footer"
```
