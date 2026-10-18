# Retention (draft)

How long each kind of personal data is kept, and what enforces it. "Built" means the rule runs in the app today;
"CHW-58" means it ships with this issue.

| Data                                       | Kept                                                                               | Enforced by                                           | Status  |
| ------------------------------------------ | ---------------------------------------------------------------------------------- | ----------------------------------------------------- | ------- |
| Customer account                           | Until the customer deletes it, or 24 months without activity (notice at 23 months) | `DeleteAccount`; the daily inactivity job             | CHW-58  |
| Stamps and rewards of a deleted account    | Kept without the person's identity, so business counts stay true                   | `DeleteAccount` anonymises; the ledger is append-only | Built   |
| Tap log (IP address, browser)              | 180 days (`TAP_RETENTION_DAYS`)                                                    | `Tap` pruning, daily `model:prune`                    | Built   |
| Sign-in links                              | 1 day                                                                              | `LoginLink` pruning                                   | Built   |
| Counter scans (member QR)                  | 1 day after they expire                                                            | `CounterScan` pruning                                 | Built   |
| Staff invitations                          | 1 month after use or expiry                                                        | `StaffInvitation` pruning                             | Built   |
| Sessions (IP address, browser)             | The session lifetime (`SESSION_LIFETIME`, 120 minutes), then garbage-collected     | Laravel session garbage collection                    | Built   |
| Consent dates (16+, news, follows)         | While the account exists; withdrawn consents keep the date of withdrawal           | `users` columns, `follows` rows                       | CHW-58  |
| Business users (owners, staff, org admins) | While the membership exists; then the account follows the customer rules           | Membership removal; `MustHandOverBusiness`            | Built   |
| Platform audit log                         | To decide (proposal: 5 years, no personal data beyond who acted)                   | Append-only; no pruning yet                           | Open    |
| Verification documents (ID, address)       | To decide with CHW-145 (proposal: 3 months after the decision)                     | Scheduled job in CHW-145                              | Planned |
| Wallet passes and push subscriptions       | While the pass or subscription exists                                              | Phase 2                                               | Planned |
| Backups                                    | To decide with the hosting (proposal: 30 days rolling)                             | ADR 0008, CHW-59                                      | Open    |
| Cache entries (dedupe and day marks)       | Up to 7 days, but expired rows stay until read or pruned                           | CHW-241                                               | Open    |

## The inactivity rule

- **Activity** means signing in or using the app while signed in, a stamp proving presence (tap, scanned QR, by staff),
  or a reward handed over. Corrections and automatic stamps (bonus, birthday, referral) don't count.
- **At 23 months** without activity the customer gets an email, in their language: the account will be deleted in 30
  days, and any unused reward will be lost; signing in keeps it.
- **At 24 months**, and never less than 30 days after the email, the account goes through the same path as a deletion the
  customer asks for: deleted if it has no history, otherwise anonymised.
- **Never swept**: owners, staff, org admins and platform admins (their accounts follow their membership), and accounts
  already anonymised.
