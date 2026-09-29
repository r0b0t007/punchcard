# 0004. Marketing website lives inside the Laravel app

- Status: accepted
- Date: 2026-09-29

## Decision

The public marketing pages (`/`, `/pricing`, `/how-it-works`, `/faq`, `/legal/*`, FR/EN/AR) are Inertia pages in the same app, with SSR enabled for them, and pricing read from the plans configuration.

## Consequences

- One deploy and design system; sign-up and plan selection flow straight into onboarding.
- SEO needs Inertia SSR (`npm run build:ssr`), per-page meta tags, a sitemap and hreflang.
- The marketing layout must stay light: no service worker control, no app bundle on public pages.
