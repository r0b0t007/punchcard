---
name: stamp-flow
description: Domain rules for stamps, rewards, redemption, cooldowns, caps, progressive tiers and referrals in punchcard. Use for any change to loyalty cards, card enrollments, stamp_events, rewards, the staff counter mode, member QR scanning, or the customer card UI.
---

# Stamp, reward and redeem flow

## Entities (see docs/spec.md "Data model")

- `loyalty_cards`: template per business. `mode` = `cyclic` (reset after reward) or `progressive` (lifetime stamps, `tiers` JSON of `{stamps, reward}`), `stamps_required` 5..50, `cooldown_min`, `daily_cap`, reward fields, design fields.
- `card_enrollments`: a customer's copy. `current_stamps`, `lifetime_stamps`, `completed_count`, `last_stamp_at`, `referral_code`, `referred_by`.
- `stamp_events`: append-only ledger. `qty`, `source` (`nfc`, `qr`, `manual`, `bonus`, `birthday`, `referral`, `correction`), `stamper_id`, `staff_id`, `location_id`. Counts on enrollments are a cache of this ledger.
- `rewards`: `available` → `redeemed` | `expired`. One row per unlocked reward.

## Sources of a stamp

| Source                          | Proof of presence                                           | Who triggers      |
| ------------------------------- | ----------------------------------------------------------- | ----------------- |
| `nfc`                           | Verified SUN tap (see sun-nfc-verification skill)           | Customer          |
| `qr`                            | Staff scans the customer's rotating member QR (30 s window) | Staff             |
| `manual` / `correction`         | Staff or owner action, reason required                      | Staff/owner       |
| `bonus`, `birthday`, `referral` | System rule                                                 | Scheduler / event |

Staff can **arm** a stamper: the next verified tap within 60 s on that stamper gives N stamps (max 10). Arming is per stamper, single use, stored on `stampers.armed_qty/armed_until`.

## Adding stamps: one Action, one transaction

`App\Actions\Stamps\AddStamps::handle(Enrollment, int $qty, StampSource, context)`:

1. Lock the enrollment row (`lockForUpdate`).
2. Check card active, stamper active (if any), cooldown (`last_stamp_at + cooldown_min`), daily cap (sum of today's qty in the location timezone). `manual`/`correction` bypass cooldown but are audited.
3. Insert `stamp_events`, update enrollment counters.
4. Cyclic: while `current_stamps >= stamps_required` → create reward, subtract `stamps_required`, `completed_count++` (overflow stamps carry over). Progressive: create a reward for each newly crossed tier; never reset.
5. After commit: dispatch `EnrollmentChanged` (wallet pass update, live feed) as queued listeners.

Idempotency: the NFC path is protected by the SUN counter; QR and staff paths take a client-generated `idempotency_key` stored on the event with a unique index.

## Redemption

`App\Actions\Rewards\RedeemReward`: requires presence proof (a verified tap on a stamper of that business within 60 s, or a staff scan). Lock the reward row; if already `redeemed`, return the existing result (idempotent), never double-redeem. Record `redeemed_by` and location.

## Referrals

Enrollment has a `referral_code`. A new customer who signs up through it gets their first stamp as usual; the referrer gets `+1` (`source=referral`) only after the friend's first **verified** stamp at the business, capped per referrer per month (card setting). Self-referral (same user or device fingerprint) is ignored.

## Tests every change needs

- Cooldown and cap boundaries (exactly at the limit, one over).
- Cyclic overflow (e.g. 9/10 + 3 stamps = reward + 2/10).
- Progressive tiers crossing two tiers in one add.
- Concurrency: two simultaneous adds do not exceed the cap (use DB locking, test with sequential calls asserting locks are taken).
- Redemption twice returns the same result, one `redeemed` row.
- Tenant isolation (ADR 0006): staff of organization A cannot stamp or redeem on organization B. Inside a franchise, staff of any participating business can stamp and redeem on the shared card, and the event records their `business_id` and `location_id`.
