# 0003. Apple/Google Wallet pass is the customer's primary card

- Status: accepted
- Date: 2026-09-29

## Context

Most café customers will not install a PWA. iOS web push only works for home-screen-installed PWAs. Wallet passes are one tap to add, visible on the lock screen, and can be updated remotely.

## Decision

Every enrollment gets an Apple Wallet store card and a Google Wallet loyalty object showing progress, the reward and a signed member QR. Pass updates after each stamp are the main notification channel on iOS. The PWA remains the account, history and discovery layer, and handles web push on Android and installed iOS.

## Consequences

- Requires the Apple Developer Program (Pass Type ID certificate, about USD 99/year) and a Google Wallet issuer account.
- Pass generation, the Apple web service endpoints and push updates are Phase 2 work.
