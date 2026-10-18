# Register of processing activities (draft)

One entry per purpose. "punchcard" means the company operating the platform; "the organization" is the business or
franchise network that runs a loyalty program (ADR 0006). Roles are explained in [roles.md](roles.md); retention periods
in [retention.md](retention.md). Hosting and transfers depend on ADR 0008: every entry below says "the hosting country"
until it is decided.

Common to every entry:

- **Hosting**: one database (PostgreSQL) and object storage in the hosting country; HTTPS only; Cloudflare in front.
- **Security**: passwords hashed (bcrypt), optional two-factor and passkeys; tenant isolation enforced in code and tested
  (an organization never reads another's data; a franchisee only its own customers); platform admin actions audited;
  secrets (tag keys, certificates) outside the code and the database; personal data never written to the logs.
- **Rights**: access and portability (download as JSON in the settings, CHW-58), rectification (profile), erasure
  (delete the account), objection to marketing (consent switches on each card and in the settings, CHW-58; an
  unsubscribe link in news emails, to build). Contact: the privacy address in the notice.
- **Consent evidence**: the current state on the account (dated), and every change in an append-only log (what, on or
  off, from a sign-up form or the settings, when), deleted with the account (CHW-58).

## T1. Customer accounts and sign-in

- **Controller**: punchcard.
- **Purpose**: let a person sign in and keep their cards across devices.
- **Legal basis**: performance of the service the person asked for.
- **People**: customers.
- **Data**: name, email, language, password hash or none (sign-in by email link), two-factor settings, passkeys (name,
  public key, last use), the date they confirmed being 16 or older, sessions (IP address, browser, last activity), date of
  last activity, sign-in links (email, one day).
- **Recipients**: punchcard staff (support, need to know).
- **Retention**: until deleted by the person, or 24 months without activity (30 days' notice), then deleted or
  anonymised.

## T2. Loyalty programs

- **Controller**: the organization that runs the program; punchcard is its processor (see roles.md for the open
  question).
- **Purpose**: record stamps and rewards on a customer's card and hand rewards over.
- **Legal basis**: performance of the program the customer joined.
- **People**: customers who joined a program.
- **Data**: the cards held (stamps, completed cards, date joined, referral code), each stamp (business, site, date, number,
  how it was given: tap, scanned QR, by staff, correction or bonus, the staff's reason on manual stamps), rewards (what,
  unlocked, redeemed where and when).
- **Recipients**: the organization's admins (all program members); a franchisee's owners and staff only for customers who
  stamped with that franchisee in person; staff at any site of the program see a customer's progress only while the
  customer is at the counter.
- **Retention**: while the account exists. On deletion or anonymisation the stamps and rewards stay, without the person's
  identity, so the businesses' counts don't change.

## T3. Tap verification and fraud prevention

- **Controller**: punchcard (for the platform and every program).
- **Purpose**: verify that a stamp was given in person at a genuine stamper, stop copied links and abuse, and tell the
  owner about signs of fraud.
- **Legal basis**: legitimate interest (the integrity of the programs and of punchcard).
- **People**: anyone who taps a stamper, signed in or not.
- **Data**: each tap (time, stamper, site, outcome, the customer if signed in, IP address, browser); refused taps shown to
  the business with the customer's first name only when it may see them (T2); fraud alerts to the business's owners.
- **Recipients**: punchcard; the business where the tap happened (refused taps, alerts; never the IP or browser).
- **Retention**: 180 days, then deleted.

## T4. punchcard news

- **Controller**: punchcard.
- **Purpose**: news and offers from punchcard itself.
- **Legal basis**: consent, opt-in, dated; can be withdrawn in the settings at any time.
- **People**: customers who opted in.
- **Data**: email, language, date of consent or withdrawal.
- **Recipients**: punchcard; the email provider.
- **Retention**: until withdrawn or the account ends.

## T5. Offers from programs a customer follows

- **Controller**: the organization.
- **Purpose**: offers and news from a program the customer chose to follow.
- **Legal basis**: consent, per program, off until turned on, dated; can be withdrawn on the card or in the settings.
- **People**: customers following a program.
- **Data**: the follow (program, on or off, since when), email, language; later the Wallet pass or push subscription
  (T7).
- **Recipients**: the organization (its admins; a franchisee only for its own customers); punchcard as processor.
- **Retention**: until withdrawn or the account ends.

## T6. Business users and administration

- **Controller**: punchcard.
- **Purpose**: let owners, staff and org admins run their business and programs; let punchcard support and verify them.
- **Legal basis**: performance of the contract with the business; legitimate interest (security and audit).
- **People**: owners, staff, org admins, platform admins.
- **Data**: the same account data as T1; membership and role per business; staff invitations (email, one month after
  use or expiry); stamps given and rewards handed over (staff identity, visible to their business); the platform audit
  log (who did what, never key material); later the verification documents (ID and proof of address, CHW-145).
- **Recipients**: punchcard; the business's owners for their own staff.
- **Retention**: while the membership exists; audit log to decide (retention.md).

## T7. Wallet passes and notifications (planned, Phase 2)

- **Controller**: the organization for program offers, punchcard for the service.
- **Purpose**: keep the card in Apple or Google Wallet and send updates and offers.
- **Legal basis**: performance (the pass itself); consent (offers, T5).
- **Data**: pass serial and device tokens, push subscription endpoint and keys.
- **Recipients and transfers**: Apple (United States) and Google (United States, EU) to deliver passes and updates; the
  browser's push service.
- **Retention**: while the pass or subscription exists.

## T8. Transactional email

- **Controller**: punchcard.
- **Purpose**: sign-in links, confirmations, staff invitations, fraud alerts to owners, the inactivity notice.
- **Legal basis**: performance of the service; legitimate interest (fraud alerts).
- **Data**: email, name, language, the message.
- **Recipients and transfers**: the email provider (to choose; a transfer if abroad).
- **Retention**: not stored by punchcard beyond the queue; the provider's logs per its terms.
