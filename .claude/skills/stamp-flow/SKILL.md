---
name: stamp-flow
description: Domain rules for stamps, rewards, redemption, cooldowns, caps, progressive tiers and referrals in punchcard. Use for any change to loyalty cards, card enrollments, stamp_events, rewards, the staff counter mode, member QR scanning, or the customer card UI.
---

# Stamp, reward and redeem flow

## Entities (see docs/spec.md "Data model")

- `loyalty_cards`: the organization's card, honoured by the businesses in `card_business` (ADR 0006). `mode` = `cyclic` (reset after reward) or `progressive` (lifetime stamps, `tiers` JSON of `{stamps, reward}`), `stamps_required` 5..50, `cooldown_min`, `daily_cap`, reward fields, design fields.
  - Once customers hold a card (`held()`), its mode and tiers are fixed and its `stamps_required` can be lowered, never raised (CHW-32, `LoyaltyCard::assertTenantWrite`, also in bypass()): lowering takes nothing, and a customer who now has enough is paid with their next stamp, one reward per full card (lowering 10 to 5 pays a customer at 9 two rewards); raising would move their goal, so start a new card instead. Only a loaded card's save changes it: bulk writes and increment() are refused. Rewards already earned keep the reward they were unlocked with.
  - Which card a customer gets at a business: `App\Support\Cards\CardChoice` (EnrollCustomer, the join page): a running card they hold, else the card the business runs now (a paused one they hold keeps its stamps), else, with none running, a refusal (CHW-148).
- `card_enrollments` (`CardEnrollment`): a customer's copy. `current_stamps`, `lifetime_stamps`, `completed_count`, `last_stamp_at`, `referral_code`, `referred_by`.
- `stamp_events`: append-only ledger. `qty`, `source` (`nfc`, `qr`, `manual`, `bonus`, `birthday`, `referral`, `correction`), `business_id`, `location_id`, `stamper_id` + `nfc_tag_id` + `counter` (nfc), `staff_id` + `idempotency_key` (qr, manual, correction), `reason` (manual, correction). Counts on enrollments are a cache of this ledger.
- `rewards`: `available` → `redeemed` | `expired`. One row per unlocked reward, a snapshot of what was earned. No expiry is set yet (no card setting).

## Sources of a stamp

| Source                          | Proof of presence                                           | Who triggers      |
| ------------------------------- | ----------------------------------------------------------- | ----------------- |
| `nfc`                           | Verified SUN tap (see sun-nfc-verification skill)           | Customer          |
| `qr`                            | Staff scans the customer's rotating member QR (30 s window) | Staff             |
| `manual`                        | Staff or owner adds stamps by hand, reason required         | Staff/owner       |
| `correction`                    | Takes stamps back (qty < 0), reason required                | Staff/owner       |
| `bonus`, `birthday`, `referral` | System rule                                                 | Scheduler / event |

Staff can **arm** a stamper: the next verified tap within 60 s on that stamper gives N stamps (max 10). Arming is per stamper, single use, stored on `stampers.armed_qty/armed_until`.

## Adding stamps: one Action, one transaction

`App\Actions\Stamps\AddStamps::handle(CardEnrollment, StampRequest): StampResult`. Build the request with `StampRequest::nfc()`, `qr()`, `manual()`, `correction()` or `system()`, which check its shape like the Postgres CHECKs. A refusal is a `StampRejected` carrying a `StampRejection` and writes nothing.

1. Lock a tap's stamper, then the enrollment row (`lockForUpdate`), the order `/t` follows (tag, stamper, enrollment). Staff must work at the business. A known idempotency key returns the earlier stamp (`replayed`) if it is the same stamp by the same person, else `IdempotencyConflict`.
2. Check: the card is active, the business honours it, the site is open (`ArchivedSites`). A correction (always negative: restoring missed stamps is a manual stamp) skips these where the stamps were given, so the ledger stays fixable, and takes back at most the stamps given at that business. Manual stamps and corrections from a tenant need a customer the business can see. For a tap, the stamper must be current, active and at that location. Staff must work at the business; outside `bypass()` the caller's business must be the stamp's.
3. Stamps that prove presence (nfc, qr, manual): the cooldown per customer per card (`last_stamp_at + cooldown_min`) and the daily cap per customer per card **per business** (today's nfc, qr and manual qty there, the day starting at midnight in the location's timezone; a null cap means none). An armed tap gives the room left under the cap; a scan or manual stamp that does not fit is refused. Corrections and system stamps follow their own rules; system jobs must pass an idempotency key, so a retried job stamps once.
4. Insert `stamp_events`, then update the enrollment counters. `last_stamp_at` moves for presence sources only. A correction never takes `current_stamps` below 0.
5. Cyclic: while `current_stamps >= stamps_required` → create reward, subtract `stamps_required`, `completed_count++` (overflow stamps carry over). Progressive: create a reward (type `item`, the tier's text, milestone = the tier's stamps) for each newly crossed tier, once even if crossed again after a correction; never reset. Only stamps that add pay out, so a correction never does. Cyclic milestones follow the highest one already there. Each reward records the stamp that unlocked it (`stamp_event_id`), which a replay returns. Tiers are validated when the card is saved (`ProgressiveTiers`) and fixed, with the mode, once customers hold the card; malformed stored tiers refuse the stamp (`CardMisconfigured`).
6. After commit: `EnrollmentChanged` (ids only, `ShouldDispatchAfterCommit`) for queued listeners (wallet pass update, live feed).

Idempotency: the NFC path is protected by the SUN counter (`unique(nfc_tag_id, counter)`); QR and staff paths take a client-generated `idempotency_key`, unique per business.

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
