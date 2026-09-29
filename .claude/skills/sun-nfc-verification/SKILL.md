---
name: sun-nfc-verification
description: How punchcard verifies NFC stamper taps (NTAG 424 DNA Secure Unique NFC / SUN, AES-128 SDM). Use when building or changing the /t tap endpoint, stamper provisioning, key derivation, replay protection, or anything that parses picc_data/cmac query parameters.
---

# SUN tap verification (NTAG 424 DNA)

Each stamper holds an NTAG 424 DNA tag configured for Secure Dynamic Messaging. Every tap makes the
tag emit a fresh URL, which the phone opens in its browser:

```
https://<tap host>/t?e=<picc_data: 32 hex>&c=<cmac: 16 hex>
```

Query names are short on purpose (NDEF space). The server must prove the URL came from a genuine tag
and was never used before. A copied URL, screenshot or shared link must fail.

## Algorithm (NXP AN12196, AES-128, encrypted PICCData, empty MAC input)

1. `e` → 16 bytes. Decrypt with the tag's **SDMMetaReadKey**: AES-128-CBC, IV = 16 zero bytes, no padding.
2. Plaintext layout: byte 0 = PICCDataTag (must have bits 7 and 6 set = UID and counter mirrored, low nibble = 7 = UID length), bytes 1..7 = UID, bytes 8..10 = SDMReadCtr **little-endian**, rest random padding.
3. Session MAC key: `KSes = CMAC(SDMFileReadKey, 3C C3 00 01 00 80 || UID || SDMReadCtr(LE))` (16 bytes).
4. `full = CMAC(KSes, "")` (MAC input is empty when SDMMACInputOffset == SDMMACOffset, which is our tag config).
5. Truncate: take bytes at odd indexes 1,3,5,…,15 of `full` → 8 bytes. Compare with `c` using `hash_equals`.
6. Look up the stamper by UID. Require `counter > stampers.last_counter`, then set `last_counter = counter`
   inside the same DB transaction, with `lockForUpdate()` on the stamper row. Also keep a unique index on
   `(stamper_id, counter)` in `stamp_events` as a second guard.

CMAC is RFC 4493 AES-CMAC. Keys are per tag, derived from the master key in `config('punchcard.nfc.sun_master_key')`
with NXP AN10922 AES-128 key diversification (input: UID + key number + system identifier). Store only
`key_version` on the stamper, never the derived keys.

## Verified reference

`reference.php` in this folder is a framework-free implementation that passes:

| Vector                      | Input                                                      | Expected                           |
| --------------------------- | ---------------------------------------------------------- | ---------------------------------- |
| AN12196 SUN (all-zero keys) | `e=EF963FF7828658A599F3041510671E88`, `c=94EED9EE65337086` | UID `04DE5F1EACC040`, counter `61` |
| RFC 4493 example 1          | key `2b7e151628aed2a6abf7158809cf4f3c`, empty message      | `bb1d6929e95937287fa37d129b756746` |

Run `php .claude/skills/sun-nfc-verification/reference.php` → `OK`. When porting into the app, put the crypto in
`app/Support/Nfc/` (pure classes, no Laravel) and copy both vectors into `tests/Unit/Nfc/` Pest tests, plus
a tampered-MAC test and a replayed-counter test.

## Rules

- Never log `e`, `c`, UIDs with keys, or derived keys. Log the stamper id and the rejection reason only.
- Reject reasons are an enum: `malformed`, `unknown_tag`, `bad_mac`, `replay`, `stamper_disabled`, `cooldown`, `daily_cap`. Record each rejected tap for the owner's fraud view.
- Rate limit `/t` per IP and per user (`RateLimiter::for('tap', ...)`).
- The endpoint is a normal GET that renders an Inertia page (C1/C2/C3/cooldown). It must work logged out: keep the verified tap in the session, finish sign-in, then apply the stamp once.
- A disabled stamper (lost/stolen) rejects everything; re-provisioning bumps `key_version`.
- Development without hardware: a `php artisan punchcard:fake-tap {stamper}` command (to build) generates valid URLs from test keys. Never enable it in production.
