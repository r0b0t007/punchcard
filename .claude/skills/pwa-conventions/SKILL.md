---
name: pwa-conventions
description: PWA rules for punchcard's customer and business surfaces (manifests, service worker caching, install prompts, iOS limits, web push, offline cards). Use when touching the manifest, service worker, vite PWA config, install UI, push subscription, or anything that must work on iPhone Safari.
---

# PWA conventions

## Surfaces

| Surface        | Path                                                                                                  | Manifest                                                                    | Notes                                                                                                                                                                                                                                                  |
| -------------- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Marketing site | `/`, `/pricing`, `/faq`, `/legal/*`                                                                   | none                                                                        | SSR-friendly, no service worker control needed                                                                                                                                                                                                         |
| Customer PWA   | `/app/*` + tap `/t`                                                                                   | `manifest-customer.webmanifest`, `start_url: /app`, `scope: /`              | Tap URLs must be in scope so Android opens them in the installed app                                                                                                                                                                                   |
| Business PWA   | `/business/*` (dashboard `/business`, cards, QR stand, onboarding, its own sign-in `/business/login`) | `manifest-business.webmanifest`, `start_url: /business`, `scope: /business` | Separate name ("punchcard Pro"). The scope keeps `/t` and `/j/*` out, so a tap never opens in it; a guest signs in at `/business/login`, inside it; the old paths redirect (CHW-151). Every new `/business…` route must be a Pro page (MovedPathsTest) |
| Admin          | `/admin` (Filament)                                                                                   | none                                                                        | Desktop only                                                                                                                                                                                                                                           |

The `pwa:customer` / `pwa:business` route middleware (`UsePwa`) picks the manifest: app.blade.php writes it on a full load and `PwaHead` keeps it in step on Inertia visits. Icons: 192, 512, maskable 512, Apple touch icon 180.

## iOS facts to design around

- iOS has no Web NFC. Taps work because the tag holds a URL that iOS opens in Safari (see sun-nfc-verification).
- Safari and the home-screen PWA do **not** share cookies or storage. The tap always lands in Safari, so keep a long-lived Safari session (remember-me, passkeys, magic link). Never require the installed PWA for stamping.
- Web push on iOS works only for home-screen-installed PWAs, after a user gesture. Wallet pass updates are the reliable iOS notification channel.
- No `beforeinstallprompt` on iOS: show the C13 instruction sheet (Share → Add to Home Screen) after the second stamp, never on first visit.

## Service worker

- Tooling: `vite-plugin-pwa` (Workbox, `injectManifest` strategy). Confirm compatibility with the project's Vite/vite-plus version before adding it; pin the version.
- Precache the app shell only (the app entry and its static imports). Register after the page has loaded (`immediate: false`), never while a tap or join page loads. Runtime caching (`resources/js/sw-routes.ts`):
    - Fonts: cache-first once a page used them (only the subsets it shows).
    - Images/logos: stale-while-revalidate, capped entries.
    - **Never cache** `/t`, auth routes, any POST/PATCH/DELETE, `/business/*` data.
- The last-known cards live in IndexedDB only (`resources/js/lib/offline-cards.ts`): My cards saves them from its props, with the language kept apart; the offline C5 screen (`/offline`, `resources/js/offline.tsx`, precached; the worker serves it for a page load under `/app` that fails, never for a slow one; a tap in the app that fails offline is answered with Inertia's 409 `X-Inertia-Location`, so it loads in full and gets it too) shows them with "offline, last updated <time>", only if they are the last signed-in customer's. There is no `/api/me/cards` cache (dropped in the review of #70).
- They are only ever the signed-in customer's: sign-out and account deletion drop them and clear Inertia's (encrypted) history; `keepOnlyCardsOf` in the outer layout drops them on any page seen by someone else or a guest. No `Clear-Site-Data`: its "storage" resets the theme and removes the service worker.
- Page loads and writes skip the worker's start-up where the browser can (static routing, `NETWORK_ROUTES`): the tap must stay fast.
- Version the SW; on update show a "New version, reload" toast rather than reloading mid-action.

## Push

- `laravel-notification-channels/webpush`, VAPID keys from env. Ask for permission only after a clear value moment (reward unlocked, or following a business), never on load.
- Store one `push_subscriptions` row per device; delete on 404/410 from the push service.

## Checks

- Lighthouse PWA installability + accessibility in CI for C2, C5, B3.
- Playwright `mobile-safari` and `mobile-chrome` projects for every customer screen.
