# Retention (draft)

How long each kind of personal data is kept, and what enforces it. "Built" means the rule runs in the app today;
"CHW-58" means it ships with this issue.

| Data                                             | Kept                                                                                | Enforced by                                           | Status  |
| ------------------------------------------------ | ----------------------------------------------------------------------------------- | ----------------------------------------------------- | ------- |
| Customer account                                 | Until the customer deletes it, or 24 months without activity (notice at 23 months)  | `DeleteAccount`; the daily inactivity job             | CHW-58  |
| Stamps and rewards of a deleted account          | Kept without the person's identity, so business counts stay true                    | `DeleteAccount` anonymises; the ledger is append-only | Built   |
| Tap log (IP address, browser)                    | 180 days (`TAP_RETENTION_DAYS`)                                                     | `Tap` pruning, daily `model:prune`                    | Built   |
| Sign-in links                                    | 1 day                                                                               | `LoginLink` pruning                                   | Built   |
| Counter scans (member QR)                        | 1 day after they expire                                                             | `CounterScan` pruning                                 | Built   |
| Staff invitations                                | 1 month after use or expiry                                                         | `StaffInvitation` pruning                             | Built   |
| Sessions (IP address, browser)                   | The session lifetime (`SESSION_LIFETIME`, 120 minutes), then garbage-collected      | Laravel session garbage collection                    | Built   |
| Consent dates and their log (16+, news, follows) | While the account exists; every change logged, withdrawals included                 | `users` columns, `follows` rows, `consent_events`     | CHW-58  |
| Business users (owners, staff, org admins)       | While the membership exists; then the account follows the customer rules            | Membership removal; `MustHandOverBusiness`            | Built   |
| Platform audit log                               | To decide (proposal: 5 years, no personal data beyond who acted)                    | Append-only; no pruning yet                           | Open    |
| Verification documents (ID, address)             | 3 months after the platform accepts or rejects them; while waiting, no limit yet    | `DeleteDecidedDocuments`, nightly at 04:00 UTC        | Built   |
| Wallet passes and push subscriptions             | While the pass or subscription exists                                               | Phase 2                                               | Planned |
| Backups                                          | In Morocco, the hosting's region (ADR 0008); how long to decide (proposal: 30 days) | Nightly to Oracle Object Storage, Casablanca; CHW-59  | Open    |
| Cache entries (dedupe and day marks)             | Up to 7 days, but expired rows stay until read or pruned                            | CHW-241                                               | Open    |

## Verification documents

- **What**: the owner's ID card or passport and a proof of the business's address, sent from "Verify your business"
  (CHW-145). JPG, PNG or PDF, 10 MB at most.
- **Stored** on the private `documents` disk, never served by Laravel, under a random name, encrypted with their own
  key (`DOCUMENTS_KEY`, AES-256-GCM), never `APP_KEY`. Only the platform admin reads them, from the business's page
  in A1 through a link that works 5 minutes, each view in the audit log (`document.viewed`).
- **Kept** 3 months after the platform decides (`punchcard.documents.retention_months`): verifying the business
  accepts them, "Ask for new documents" in A1 rejects them, with a reason the owners get by email. The date is the start of that day (UTC), so
  the night's run deletes them on the day the owner's page names. A new document replaces the one waiting or
  rejected, file and row.
- **Built** (CHW-145): every night at 04:00 UTC `DeleteDecidedDocuments` deletes each due document's file, then its row.
  A file that won't go keeps its row for the next night. It also deletes the files no row points at once they are a
  day old: an upload that failed before its row, a replaced file that wouldn't go, a business deleted with its rows.
  It logs counts, and a failure by document id or path only.
- **Owner's account deleted**: the documents stay until their date; `uploaded_by` is cleared, or points at the
  anonymised account when it had history (`DeleteAccount`).
- **Key rotation**: a key moved to `DOCUMENTS_PREVIOUS_KEYS` can be dropped once no row has its `key_id` (the first 16
  characters of its SHA-256). Documents still waiting have no date, so count rows, never months.
- **One disk**: the `documents` disk is local, and the job runs on one server. With more than one app server it must
  move to shared storage first, or the job deletes rows whose files sit on another server.
- **Open**: a document still waiting has no date, so a business that is never verified (closed, suspended, or its
  owner gone) keeps it. Proposal: delete documents waiting longer than 12 months, or when the business is closed.
  To decide before the CNDP filing.

## The inactivity rule

- **Activity** means signing in or using the app while signed in, a stamp proving presence (tap, scanned QR, by staff),
  or a reward handed over. Corrections and automatic stamps (bonus, birthday, referral) don't count.
- **At 23 months** without activity the customer gets an email, in their language: the account will be deleted in 30
  days, and any unused reward will be lost; signing in keeps it.
- **At 24 months**, and never less than 30 days after the email, the account goes through the same path as a deletion the
  customer asks for: deleted if it has no history, otherwise anonymised.
- **Never swept**: owners, staff, org admins and platform admins (their accounts follow their membership), and accounts
  already anonymised.
- **Built** (CHW-58): `users.last_active_at` (`RecordActivity`), then every night at 03:30 UTC `WarnInactiveAccounts`
  and `AnonymiseInactiveAccounts`, limits in `config/punchcard.php` (`privacy`). A warning is written only after its
  email went, with the day it promised (`inactivity_deletes_at`): never deleted before that day, whatever the limits
  say later. A tap, stamp, redemption or sign-in as the sweep runs keeps the account. The jobs log counts, and an
  account that fails by id only, never a name or address.
