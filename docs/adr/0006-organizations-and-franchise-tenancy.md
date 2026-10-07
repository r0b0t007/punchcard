# 0006. Organizations above businesses for chains and franchises

- Status: accepted
- Date: 2026-09-30

## Context

Chains of 5 to 20 sites are the target segment. Two shapes exist:

- **Owned chain**: one company, many sites, one bank account. One business with many locations covers it.
- **Franchise network**: each site is its own legal entity that pays for itself, but the brand runs one card program. A customer
  who collects 4 stamps in Tétouan and 3 in Tangier expects 7 on the same card.

The first data model had `businesses` as the tenant root, so a franchise network could not share a card. Adding a level above
the tenant root after tenancy is built means rewriting every scope, policy and isolation test, so we add it now.

## Decision

Three levels, always present:

```
organization  (brand: the card program, brand identity, optional white-label domain)
  └─ business  (legal entity: staff, stampers, billing when paid per site)
       └─ location  (a physical site: address, timezone, stampers)
```

- Every business belongs to exactly one organization. Signing up an independent café creates an organization of type
  `independent` with one business, and the UI hides the organization level unless the type is `chain` or `franchise`.
- An owned chain is one organization, one business, many locations. A franchise network is one organization with one
  business per franchisee.
- **Card programs belong to the organization.** `loyalty_cards.organization_id` is the owner. A `card_business` pivot lists
  which businesses honour the card (all by default). Enrollments, stamps and rewards accumulate per card, so progress is
  shared across every participating site.
- **Attribution stays at the site.** `stamp_events` records `business_id` and `location_id`; `rewards` records
  `redeemed_business_id` and `redeemed_location_id`. This powers the franchise report "stamps earned here vs rewards
  redeemed here". punchcard reports it and does not settle money between franchisees.
- **Two tenant scopes.** `BelongsToOrganization` for program data (cards, enrollments, rewards, org-wide campaigns) and
  `BelongsToBusiness` for site data (locations, stampers, staff, business campaigns). Business-scoped models also carry
  `organization_id` so org-level reports never join across tenants.
- **Roles**: `org_admin` (franchisor HQ: card program, brand, org-wide campaigns and reports), `owner` (one business:
  locations, stampers, staff, local stats and campaigns, billing when paid per site), `staff` (stamp, arm, scan, redeem,
  optionally limited to one location), `customer` (global), `admin` (platform). Org roles come from `organization_user`;
  business roles from `business_user` (`role`, `location_id`): one source of truth, read through `TenantContext` by the
  policies. spatie/laravel-permission holds only the platform `admin` role, without teams (amended 2026-10-06, CHW-22).
  The owner of the only business of a non-franchise organization acts as its org admin; a customer is any user, not a
  stored role.
- **Customer data inside a franchise**: staff at any participating site can see a customer's progress on the shared card
  when the customer is present (needed to stamp and redeem). Customer lists, contact details and marketing audiences for a
  franchisee include only customers with a stamp event at that franchisee. The org_admin sees all program members. The
  organization is the data controller for the program (to confirm with the CNDP filing, CHW-58).
- **Billing entity** is either the organization (one invoice for the network) or each business. Chosen per organization;
  the payment decision itself stays in CHW-51.
- **MVP scope**: the schema, scopes, roles and isolation tests ship in Phase 1 (CHW-21, CHW-22). The franchise console,
  franchisee invites and cross-site reports ship in Phase 4.

## Consequences

- Isolation tests cover two boundaries: organization A vs organization B, and franchisee A1 vs franchisee A2 inside the
  same organization.
- The tap endpoint resolves stamper → business → organization → the card active at that business. MVP keeps one active
  card per business (either its own organization card or the franchise card).
- Independent cafés pay no complexity cost in the UI; the extra level is invisible until a chain or franchise needs it.
- White-label branding and custom domains attach to the organization (ADR 0007).
