---
name: design-tokens
description: punchcard visual design tokens and how they map to Tailwind 4 and shadcn/ui, including business brand colours on loyalty cards, dark mode and RTL. Use when building or restyling any UI screen, importing designs from Claude Design or Google Stitch, or touching resources/css/app.css.
---

# Design tokens

Source of truth for the look: the design system produced from the Claude Design / Google Stitch prompts in
`docs/spec.md`. Until final tokens are exported, use these defaults and keep names stable.

## Palette (light / dark)

| Token                                       | Light                              | Dark             | Use                              |
| ------------------------------------------- | ---------------------------------- | ---------------- | -------------------------------- |
| `--background`                              | cream `#FBF6EE`                    | `#17120E`        | App background                   |
| `--foreground`                              | `#241A13`                          | `#F5EEE4`        | Body text                        |
| `--primary`                                 | espresso `#3B2A20`                 | `#E9D9C6`        | Primary buttons, app chrome      |
| `--accent`                                  | saffron `#F2A541`                  | `#F2A541`        | Stamps, rewards, highlights only |
| `--success` / `--warning` / `--destructive` | green / amber / red at AA contrast | lighter variants | Status                           |
| `--card-brand`                              | per business                       | per business     | Loyalty card background only     |

Rules: a business brand colour may only change the **loyalty card** component (`--card-brand`, `--card-brand-foreground`), never the app chrome. Compute the foreground colour for contrast (WCAG AA 4.5:1) server-side when the owner saves the colour.

## Where tokens live

- Tailwind 4 `@theme` + shadcn CSS variables in `resources/css/app.css` (`:root` and `.dark`). Do not hardcode hex values in components; use `bg-primary`, `text-accent`, etc.
- Radius: cards 20px (`rounded-[20px]` via `--radius-card`), buttons 14px, chips full.
- Type: one rounded humanist sans for UI, one display face for card titles and reward moments (loaded through the `bunny()` fonts helper in `vite.config.ts`). Scale 12–40px.
- Touch targets ≥ 44px; counter-mode buttons ≥ 72px.

## Components

- `LoyaltyCard` is the signature component: logo, business name, stamp grid (5–50), stamp style (dot, ring, check, heart, star, logo), ink-stamp look with slight rotation for filled stamps, reward line, progress text. Build it once in `resources/js/components/loyalty-card/` and reuse it in C2, C5, C6, B6 preview and marketing screenshots.
- Use existing shadcn/ui components in `resources/js/components/ui/` before creating new ones.

## RTL and i18n

- Set `<html lang dir>` from the locale; Arabic = `rtl`.
- Use logical utilities only: `ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`. Mirror directional icons (chevrons, arrows) with `rtl:rotate-180`.

## Importing designs

Claude Design / Stitch exports are references, not code to paste. Rebuild with our components and tokens, then compare in Playwright screenshots (mobile-safari) in light, dark and Arabic.
