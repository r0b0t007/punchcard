# 0007. Card-level branding for everyone, white-label for Enterprise

- Status: accepted
- Date: 2026-09-30

## Context

Every business wants its logo and colours in front of customers. A few chains will want the whole experience under their
own name and domain. The customer PWA is shared: one app holds cards from many businesses, and one Apple/Google Wallet
issuer account signs every pass.

## Decision

**All plans: branding on the customer-facing surfaces a business owns.**

- Organization brand: name, logo, brand colour, optional cover image. Cards inherit it; a business in a franchise cannot
  override the franchise card's look.
- Branded surfaces: the loyalty card, the tap landing screens (C1 to C4 and cooldown), the Wallet pass (logo, colours,
  strip image, organization name), push titles, and the business name as email sender name.
- The app chrome (navigation, buttons, backgrounds) stays punchcard. A brand colour only sets `--card-brand` and
  `--card-brand-foreground`, with the foreground computed server-side for 4.5:1 contrast.

**Enterprise add-on (Phase 4): white-label per organization.**

- Custom domain (for example `fidelite.cafexyz.ma`) stored in `organization_domains`, verified by DNS, with TLS issued
  automatically (Caddy on-demand TLS with an `ask` endpoint, or Cloudflare for SaaS; decided in the white-label spike).
- The request's `Host` resolves the organization. On a white-label domain the customer PWA shows only that organization's
  cards, uses a per-organization `manifest.webmanifest` (name, short name, icons, theme colour) and may override the chrome
  tokens (`--primary`, `--accent`, `--background`) within contrast rules.
- Transactional email from the organization's name and logo; custom sending domain later.
- Kept on punchcard even for white-label: the NFC tap domain (burned into tags; a white-label domain may redirect but the
  tag URL never changes), the Apple Pass Type ID and Google issuer account, the business portal and Filament admin.

## Consequences

- A white-label PWA is a separate origin: separate install, storage and session. The customer signs in again there and
  sees only that organization's cards. The shared punchcard app still shows all their cards.
- Passkeys are bound to a domain (WebAuthn RP ID), so passkeys created on punchcard do not work on a white-label domain.
  Sign in with Apple and Google OAuth need each domain registered. The spike confirms the sign-in options per domain.
- Theming uses CSS variables resolved at request time, so no per-tenant build is needed.
- Pricing: white-label is sold as an Enterprise add-on on quote, not part of the two self-serve plans (B1).
