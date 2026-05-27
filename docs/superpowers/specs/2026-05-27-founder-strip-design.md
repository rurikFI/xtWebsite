# Founder Strip — Design Spec
**Date:** 2026-05-27  
**Status:** Approved

## Goal
Add a founder strip to the landing page that leverages the family company story as a trust and differentiation signal, placed early enough to make all subsequent product content feel more credible.

## Placement
Between `<Features>` and `<Kits>` in all three locale index pages (EN, SV, FI). Implemented as a new component: `FounderStrip.astro`.

## Content

### Headline (label)
> "This started under a housefloor in the Finnish archipelago."

This replaces the typical "Our Story" label. It is specific, unexpected, and pulls the reader in.

### Quote
> "I was completely sure that there would exist a tool like the Xtruder already, but I could not find it."

Attribution: **— Patrik Örtendahl, co-founder**

### Grounding sentence
> "That thought came to him in 2012, while insulating pipes under his house in southern Finland. Six years later, the Xtruder exists."

### Founder cards
Two portrait cards, side by side on desktop, stacked on mobile:
- **Patrik Örtendahl** — Co-founder & Inventor
- **Rurik Örtendahl** — Co-founder & Builder

Each card has a circular photo placeholder (avatar icon) ready to be swapped for a real photo later. Cards show name and role beneath the avatar.

### CTA link
`"Read our full story →"` linking to `/about-us/` (locale-aware: `/sv/about-us/`, `/fi/about-us/`).

## Visual Design
- Slightly warmer/lighter background than surrounding sections to read as a deliberate editorial pause — not another product block. A faint brand-tinted surface (`bg-brand/[0.03]` or `bg-orange-50/60`) in light mode; slightly lighter card surface in dark mode.
- No heavy borders. Soft shadow. Feels warm and human.
- Two-column layout on desktop (quote left, founder cards right), stacked single column on mobile.
- Quote rendered in large italic type, visually distinct from body copy.
- Consistent with existing site design system (Racing Sans One headings, Nunito Sans body, brand orange `#E8490C`, rounded-2xl cards, dark mode support).

## Translations
All three locales get full translations of the headline, quote, grounding sentence, and role titles. The quote is translated naturally (not word-for-word literally).

### Swedish (sv)
- Headline: "Det började under ett golvbjälklag i den finska skärgården."
- Quote: "Jag var helt säker på att ett sådant verktyg redan existerade, men jag kunde inte hitta det."
- Grounding: "Den tanken fick han 2012, när han isolerade rör under sitt hus i södra Finland. Sex år senare finns Xtruder."
- Roles: "Medgrundare & Uppfinnare" / "Medgrundare & Byggare"

### Finnish (fi)
- Headline: "Tämä sai alkunsa lattian alla Suomen saaristossa."
- Quote: "Olin täysin varma, että tällainen työkalu oli jo olemassa, mutta en löytänyt sitä mistään."
- Grounding: "Ajatus tuli hänelle vuonna 2012, kun hän eristää putkia talonsa alla Etelä-Suomessa. Kuusi vuotta myöhemmin Xtruder on olemassa."
- Roles: "Perustaja & Keksijä" / "Perustaja & Rakentaja"

## Photo Slots
Avatar placeholders use a simple SVG person icon in a circle, styled consistently. When real photos are available, swap the SVG for an `<img>` tag with the same circular crop. No structural changes needed.

## Files to Create/Modify
- **Create:** `starry-shell/src/components/FounderStrip.astro`
- **Modify:** `starry-shell/src/i18n/translations.ts` — add `founderStrip` key to EN, SV, FI
- **Modify:** `starry-shell/src/pages/index.astro` — add `<FounderStrip lang="en" />` between Features and Kits
- **Modify:** `starry-shell/src/pages/sv/index.astro` — same
- **Modify:** `starry-shell/src/pages/fi/index.astro` — same
