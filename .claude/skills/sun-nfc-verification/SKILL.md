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

1. `e` → 16 bytes. Decrypt with the **SDMMetaReadKey**: AES-128-CBC, IV = 16 zero bytes, no padding.
2. Plaintext layout: byte 0 = PICCDataTag (must have bits 7 and 6 set = UID and counter mirrored, low nibble = 7 = UID length), bytes 1..7 = UID, bytes 8..10 = SDMReadCtr **little-endian**, rest random padding.
3. Session MAC key: `KSes = CMAC(SDMFileReadKey, 3C C3 00 01 00 80 || UID || SDMReadCtr(LE))` (16 bytes).
4. `full = CMAC(KSes, "")` (MAC input is empty when SDMMACInputOffset == SDMMACOffset, which is our tag config).
5. Truncate: take bytes at odd indexes 1,3,5,…,15 of `full` → 8 bytes. Compare with `c` using `hash_equals`.
6. Look up the stamper by UID. Require `counter > stampers.last_counter`, then set `last_counter = counter`
   inside the same DB transaction, with `lockForUpdate()` on the stamper row. Also keep a unique index on
   `(stamper_id, counter)` in `stamp_events` as a second guard.

CMAC is RFC 4493 AES-CMAC. Both keys come from the master key in `config('punchcard.nfc.sun_master_key')`
with NXP AN10922 AES-128 key diversification, but not with the same input:

- **SDMMetaReadKey** is system-wide (input: key number + system identifier + the global meta-key version from
  `config('punchcard.nfc.key_version')`, no UID). It has to be: the UID is inside the encrypted `e`, so the server
  cannot know which tag tapped, or that tag's version, until it has decrypted it. Leaking it only lets someone read
  UIDs and counters (link taps); it cannot forge one.
- **SDMFileReadKey** is per tag (input: UID + key number + system identifier + the stamper's own `key_version`). It
  is the key that proves authenticity, so a key extracted from one tag cannot sign taps for another.
- Start both diversification inputs with a fixed purpose byte (or the key number) so the two can never collide.

Store only `key_version` on the stamper, never the derived keys; it diversifies the **file key only**.
Re-provisioning one stamper bumps its `key_version`. Rotating the meta key means re-provisioning every tag. Config holds
one meta version today, so changing `NFC_SUN_KEY_VERSION` is a hard cutover: tags not yet re-provisioned fail as
`malformed`. A gradual rollover needs a list of live meta versions in config (decide in CHW-18); the verifier then
tries each one and keeps the candidate whose **MAC verifies**, never the first one whose tag byte decodes (a wrong
key passes that check about 1 time in 256).

In code: `App\Support\Nfc\SunVerifier::decrypt($e, $metaReadKey)` returns a `SunMessage` (UID + counter, not yet
trusted); derive the file key from its UID; then `verifyMac($message, $c, $fileReadKey)` returns a `VerifiedTap`.
Only a `VerifiedTap` may reach the replay check and the stamp; `SunMessage` and `VerifiedTap` have private
constructors, so nothing else can create them. Both methods throw `SunVerificationFailed` with a `SunFailure` reason
(`malformed` or `bad_mac`) for a bad tap, and `InvalidArgumentException` for a key that is not 16 bytes: that is a
server bug, never a tap to record in the fraud view. Keys, `e` and `c` parameters are `#[\SensitiveParameter]`, so
stack traces never carry them; keep that on any new function that takes them.

## Verified reference

`reference.php` in this folder is a framework-free implementation that passes:

| Vector                      | Input                                                      | Expected                           |
| --------------------------- | ---------------------------------------------------------- | ---------------------------------- |
| AN12196 SUN (all-zero keys) | `e=EF963FF7828658A599F3041510671E88`, `c=94EED9EE65337086` | UID `04DE5F1EACC040`, counter `61` |
| RFC 4493 example 1          | key `2b7e151628aed2a6abf7158809cf4f3c`, empty message      | `bb1d6929e95937287fa37d129b756746` |

Run `php .claude/skills/sun-nfc-verification/reference.php` → `OK`. The app's port lives in `app/Support/Nfc/`
(pure classes, no Laravel), tested in `tests/Unit/Nfc/` with both vectors plus all four RFC 4493 examples,
tampered-MAC, wrong-key and malformed-input cases. The replayed-counter test belongs with the `/t` endpoint,
because replay protection is the stamper row lock in the database.

## Rules

- Never log `e`, `c`, UIDs with keys, or derived keys. Log the stamper id and the rejection reason only.
- Reject reasons are an enum: `malformed`, `unknown_tag`, `bad_mac`, `replay`, `stamper_disabled`, `cooldown`, `daily_cap`. Record each rejected tap for the owner's fraud view.
- Rate limit `/t` per IP and per user (`RateLimiter::for('tap', ...)`).
- The endpoint is a normal GET that renders an Inertia page (C1/C2/C3/cooldown). It must work logged out: keep the verified tap in the session, finish sign-in, then apply the stamp once.
- A disabled stamper (lost/stolen) rejects everything; re-provisioning bumps `key_version`.
- Development without hardware: a `php artisan punchcard:fake-tap {stamper}` command (to build) generates valid URLs from test keys. Never enable it in production.
