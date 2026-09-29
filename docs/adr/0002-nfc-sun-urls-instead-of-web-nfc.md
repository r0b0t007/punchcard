# 0002. NFC taps via signed tag URLs (NTAG 424 DNA SUN), not Web NFC

- Status: accepted
- Date: 2026-09-29

## Context

The product's edge is a battery-free counter stamper. A PWA cannot read NFC on iPhone (no Web NFC in Safari). Static URL tags or printed QR codes (the approach of the local competitor Wallio) can be copied and replayed from home.

## Decision

Stampers use NTAG 424 DNA tags with Secure Dynamic Messaging. Each tap produces a URL with encrypted PICCData (UID + counter) and a truncated AES-CMAC. The server verifies the MAC (AN12196), requires a strictly increasing counter per tag, and derives per-tag keys from a master key (AN10922). iPhones open the URL natively from the lock screen; Android does the same, and can optionally use Web NFC in-app. Phones without NFC use a rotating member QR scanned by staff.

## Consequences

- Copied URLs fail (replay protection), which is the main selling point against static QR loyalty.
- Hardware cost around 1–2 USD per tag plus a body; tags need a provisioning step and key storage.
- A QR-only plan (no hardware) remains possible and is the cheaper entry tier.
- Reference implementation and test vectors: `.claude/skills/sun-nfc-verification/`.
