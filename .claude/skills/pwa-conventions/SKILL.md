---
name: pwa-conventions
description: PWA rules for punchcard's customer and business surfaces (manifests, service worker caching, install prompts, iOS limits, web push, offline cards). Use when touching the manifest, service worker, vite PWA config, install UI, push subscription, or anything that must work on iPhone Safari.
---

# PWA conventions

## Surfaces

| Surface        | Path                                             | Manifest                                                                     | Notes                                                                |
| -------------- | ------------------------------------------------ | ---------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| Marketing site | `/`, `/pricing`, `/faq`, `/legal/*`              | none                                                                         | SSR-friendly, no service worker control needed                       |
| Customer PWA   | `/app/*` + tap `/t`                              | `manifest-customer.webmanifest`, `start_url: /app`, `scope: /`               | Tap URLs must be in scope so Android opens them in the installed app |
| Business PWA   | `/dashboard`, `/cards`, `/business/*`, `/onboarding/*` | `manifest-business.webmanifest`, `start_url: /dashboard`, `scope: /` for now | Separate name ("punchcard Pro"). Narrowing its scope to `/business/` needs the routes moved there first (CHW-151) |
| Admin          | `/admin` (Filament)                              | none                                                                         | Desktop only                                                         |

The `pwa:customer` / `pwa:business` route middleware (`UsePwa`) picks the manifest: app.blade.php writes it on a full load and `PwaHead` keeps it in step on Inertia visits. Icons: 192, 512, maskable 512, Apple touch icon 180.

## iOS facts to design around

- iOS has no Web NFC. Taps work because the tag holds a URL that iOS opens in Safari (see sun-nfc-verification).
- Safari and the home-screen PWA do **not** share cookies or storage. The tap always lands in Safari, so keep a long-lived Safari session (remember-me, passkeys, magic link). Never require the installed PWA for stamping.
- Web push on iOS works only for home-screen-installed PWAs, after a user gesture. Wallet pass updates are the reliable iOS notification channel.
- No `beforeinstallprompt` on iOS: show the C13 instruction sheet (Share → Add to Home Screen) after the second stamp, never on first visit.

## Service worker

- Tooling: `vite-plugin-pwa` (Workbox, `injectManifest` strategy). Confirm compatibility with the project's Vite/vite-plus version before adding it; pin the version.
- Precache the app shell only (the app entry and its static imports). Register after the page has loaded (`immediate: false`), never while a tap or join page loads. Runtime caching (`resources/js/sw-routes.ts`):
    - `GET /api/me/cards`: network-first, fall back to cache (offline card view). Cleared on sign-out (`Clear-Site-Data`), as it holds one customer's cards.
    - Fonts: cache-first once a page used them (only the subsets it shows).
    - Images/logos: stale-while-revalidate, capped entries.
    - **Never cache** `/t`, auth routes, any POST/PATCH/DELETE, `/business/*` data.
- Store the last-known cards in IndexedDB for the offline C5 screen; show "offline, last updated <time>".
- Page loads and writes skip the worker's start-up where the browser can (static routing, `NETWORK_ROUTES`): the tap must stay fast.
- Version the SW; on update show a "New version, reload" toast rather than reloading mid-action.

## Push

- `laravel-notification-channels/webpush`, VAPID keys from env. Ask for permission only after a clear value moment (reward unlocked, or following a business), never on load.
- Store one `push_subscriptions` row per device; delete on 404/410 from the push service.

## Checks

- Lighthouse PWA installability + accessibility in CI for C2, C5, B3.
- Playwright `mobile-safari` and `mobile-chrome` projects for every customer screen.
