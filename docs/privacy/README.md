# Privacy and data protection

punchcard processes personal data of customers (people who collect stamps) and of business users (owners, staff, org
admins). In Morocco this falls under Law 09-08 and must be declared to the CNDP before real customer data is collected;
customers in France or elsewhere in the EU are also covered by the GDPR. These documents prepare that work (CHW-58).

**These are drafts, not legal advice.** Have them checked by counsel or with the CNDP before filing or publishing.

| Document                                                     | What it is                                                                   | Status                   |
| ------------------------------------------------------------ | ---------------------------------------------------------------------------- | ------------------------ |
| [processing-register.md](processing-register.md)             | Register of processing activities (one entry per purpose)                    | Draft                    |
| [cndp-declaration-draft.fr.md](cndp-declaration-draft.fr.md) | The declaration to the CNDP, in French, following its form, with a checklist | Draft, not filed         |
| [retention.md](retention.md)                                 | How long each kind of data is kept, and what enforces it                     | Draft; parts built later |
| [roles.md](roles.md)                                         | Who is controller and who is processor, franchises included (ADR 0006)       | Draft, decided           |
| [privacy-notice.draft.md](privacy-notice.draft.md)           | The customer-facing privacy notice                                           | Draft, not published     |
| [business-data-terms.draft.md](business-data-terms.draft.md) | Data processing clauses for businesses and franchise networks                | Draft, not published     |
| [ADR 0008](../adr/0008-hosting-location.md)                  | Where the data is hosted: Morocco, Oracle Cloud (Casablanca)                 | Accepted                 |

## Still open

- **Hosting provider's law** (ADR 0008, decided: Morocco, Oracle Cloud). To confirm with the CNDP whether a US provider
  whose data stays in Morocco needs any transfer formality.
- **Resend's transfer to the United States**: it keeps email logs and metadata there, a country not recognised as
  adequate. To confirm with the CNDP: authorisation, or an exception of the law (performing the service asked for).
- **Sub-processors** still to choose: Apple and Google Wallet (Phase 2), web push services (Phase 2). Chosen: Resend
  (email) and Sentry, EU region (error monitoring). Each one abroad is a transfer to declare.
- **Retention** of the audit log (retention.md). Decided: backups 30 days, verification documents still waiting 12
  months after upload (to build).
- **Filing** the declaration (the founder's step) and printing its receipt number in the privacy notice.
- **Publishing** the privacy notice and business terms on the marketing site.

## What the app already does

- Account deletion: an account with no history is deleted; one with history is anonymised and the stamp ledger keeps
  the businesses' counts without anyone's identity (`app/Actions/Account/DeleteAccount.php`, CHW-139).
- Consent at sign-up (`app/Actions/Auth/RecordConsent.php`): the confirmation of being 16 or older, always dated; news
  from punchcard dated only when the box is ticked (an unticked box records nothing, so nothing is assumed). Per-program
  offers through follows, off until turned on (`SetFollow`); a follow keeps when it was first turned on and when it last
  changed.
- Tap log IP and device kept 180 days (`taps` pruning), never written to the logs.
- Franchise isolation: a franchisee only sees customers who stamped with it in person (ADR 0006).

Live with CHW-58: news on and off in the settings with each change logged (C12, `consent_events`), the places followed
in the settings, downloading one's data (JSON, after confirming it's them), and the inactivity rule (retention.md):
last use noted, a warning email 30 days ahead, then deletion or anonymisation. Still to come: an unsubscribe link in news
emails. The drafts below describe the app once they are live: check each one before filing or publishing.
