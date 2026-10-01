---
name: design-tokens
description: punchcard visual design tokens and how they map to Tailwind 4 and shadcn/ui, including business brand colours on loyalty cards, dark mode and RTL. Use when building or restyling any UI screen, importing designs from Claude Design or Google Stitch, or touching resources/css/app.css.
---

# Design tokens

Source of truth for the look: the design system produced from the Claude Design / Google Stitch prompts in
`docs/spec.md`. Until final tokens are exported, use these defaults and keep names stable.

## Palette (light / dark)

Encoded in `resources/css/app.css` (CHW-13). Every text pair is at least 4.5:1, control borders and focus rings at least 3:1, in both themes.

| Token                                       | Light                             | Dark                              | Use                                                                    |
| ------------------------------------------- | --------------------------------- | --------------------------------- | ---------------------------------------------------------------------- |
| `--background` / `--card`                   | cream `#FBF6EE` / `#FFFDF9`       | `#17120E` / `#211A15`             | App background / raised surfaces                                       |
| `--foreground`                              | `#241A13`                         | `#F5EEE4`                         | Body text                                                              |
| `--primary`                                 | espresso `#3B2A20`                | `#E9D9C6`                         | Primary buttons, app chrome                                            |
| `--stamp`                                   | saffron `#F2A541`                 | `#F2A541`                         | Stamps, rewards, highlights only (`bg-stamp`, `text-stamp-foreground`) |
| `--accent`                                  | `#F1E7DA`                         | `#2C231D`                         | shadcn hover surface (menus, ghost buttons); not a brand colour        |
| `--muted-foreground`                        | `#6B5A4C`                         | `#B5A594`                         | Secondary text                                                         |
| `--success` / `--warning` / `--destructive` | `#2E7D4F` / `#9A5B0A` / `#B42318` | `#6CC48F` / `#F2B45A` / `#F0806B` | Status text and chips                                                  |
| `--input` / `--ring`                        | `#8F7B6A` / `#A8641A`             | `#7D6C5F` / `#F2A541`             | Control borders / focus ring                                           |
| `--card-brand`                              | per organization                  | per organization                  | Loyalty card, tap screens and Wallet pass only                         |

Saffron is `--stamp`, not `--accent`: shadcn uses `--accent` for every hover, so saffron there would leak into all menus and ghost buttons.

Rules (ADR 0007): the organization's brand colour may only change the **loyalty card**, the tap screens (C1 to C4, cooldown) and the Wallet pass (`--card-brand`, `--card-brand-foreground`), never the app chrome. Compute the foreground colour for contrast (WCAG AA 4.5:1) server-side when the owner saves the colour. Exception: on an Enterprise white-label domain the request-time theme may also override `--primary`, `--accent` and `--background`, set as CSS variables from the organization's settings, never as hardcoded classes.

## Where tokens live

- Tailwind 4 `@theme` + shadcn CSS variables in `resources/css/app.css` (`:root` and `.dark`). Do not hardcode hex values in components; use `bg-primary`, `text-accent`, etc.
- Radius: define `--radius-card: 20px` and `--radius-button: 14px` in `@theme` and use `rounded-card` / `rounded-button`; chips `rounded-full`. No arbitrary values like `rounded-[20px]` (they will fail `@shadcn/lint`).
- Type: **Readex Pro** for UI (`font-sans`), **Baloo Bhaijaan 2** for card titles, the wordmark and reward moments (`font-display`); both cover Latin and Arabic and load through `bunny()` in `vite.config.ts`. Scale 12–40px.
- Touch targets ≥ 44px (`Button` default and `Input` are 44px, `size="lg"` 56px); counter-mode buttons ≥ 72px.

## Components

- `LoyaltyCard` (`resources/js/components/loyalty-card/`) is the signature component: logo, business name, stamp grid (5–50, balanced rows), stamp style (`dot`, `ring`, `check`, `heart`, `star`, `logo`), ink-stamp look with slight rotation for filled stamps, reward line, progress text. Props: `businessName`, `cardName?`, `logoUrl?`, `stampsRequired`, `stampsCollected`, `stampStyle?`, `brandColor?`, `brandForeground?` (stored by the server; used only while it still reaches 4.5:1 on the brand colour, otherwise `cardInks()` in `@/lib/color` computes one), `rewardText`. Reuse it in C2, C5, C6, the B6 preview and marketing screenshots.
- Brand colours reach the card only as `--card-brand`, `--card-brand-foreground` and `--card-stamp` set on the card element; their Tailwind colours live in `@theme inline`, because a plain `@theme` entry resolves once on `:root` and ignores per-element overrides. Stamps are saffron when that reaches 3:1 on the brand colour (`stampColor()`), otherwise the foreground.
- Preview components at `/dev/components` (registered only when `APP_ENV=local`).
- Use existing shadcn/ui components in `resources/js/components/ui/` before creating new ones.

## Lint (`@shadcn/lint`)

`npm run check` runs six design-system rules as errors (configured in the `lint` block of `vite.config.ts`). Each error names the token, variant or size to use instead; apply it rather than disabling the rule.

- `no-raw-colors`, `no-arbitrary-values`, `no-unknown-classes`: theme tokens and the Tailwind scale only (`rounded-card`, not `rounded-[20px]`; `bg-stamp`, not `bg-amber-500`).
- `no-restyle`: on a `components/ui` component, only layout classes (margin, width, position) are allowed in `className`. For colour, shape or padding, use a variant or size, or add one to the component when the design asks for it. Contracts: `SidebarGroup` also takes spacing, `Input` also takes `ps-*`/`pe-*` (room for an adornment such as the password eye button).
- `no-inline-styles`: no `style` properties, except CSS custom properties carrying runtime values, as `LoyaltyCard` does with `--card-brand`. A hardcoded colour in a custom property is still an error.
- `require-static-classes`: no built class names like `` `bg-${color}` ``; map values to full class strings.
- `components/ui/*` is ignored (shadcn source). `pages/welcome.tsx` skips the colour and arbitrary-value rules until the marketing home replaces it (CHW-62).
- The plugin reads component files with `oxc-parser` when its native binary loads (CI on Linux) and falls back to `@typescript-eslint/parser` otherwise (a Windows Application Control policy blocks the binary on the main dev machine). The choice cannot be pinned in config, so **CI is authoritative**; if local and CI ever disagree, fix what CI reports.
- Variants added for treatments the rules would otherwise reject: `Button` `ghost-destructive` (quiet delete actions in lists), `SidebarMenuButton` `trigger` (opens a menu, stays highlighted while open) and `muted` (secondary links). Utility `transition-size` animates width and height only.

## RTL and i18n

- Set `<html lang dir>` from the locale; Arabic = `rtl`.
- Use logical utilities only: `ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`. Mirror directional icons (chevrons, arrows) with `rtl:rotate-180`.

## Importing designs

Claude Design / Stitch exports are references, not code to paste. Rebuild with our components and tokens, then compare in Playwright screenshots (mobile-safari) in light, dark and Arabic.
