# 0008. Where customer data is hosted (EU or Morocco)

- Status: proposed, decision deferred (CHW-58)
- Date: 2026-10-18

## Context

The CNDP declaration (Law 09-08) names the country where personal data is hosted and every transfer abroad, so it can't
be filed until this is decided, and real customer data can't be collected before it is filed. The spec's stack assumes
Hetzner + Ploi with Cloudflare in front and R2 storage (docs/spec.md, Architecture), which puts the data in the EU.
Customers are mostly in Morocco; some will be in France (GDPR).

Some processing is abroad whatever we choose: Cloudflare (network), the transactional email provider, error monitoring
(Sentry), and in Phase 2 Apple and Google Wallet and the browsers' push services. Those transfers are declared in both
options.

## Options

**A. EU (e.g. Hetzner Germany or Finland, OVHcloud or Scaleway in France)**

- Transfer of the whole database to an EU country, which the CNDP treats as offering adequate protection under article
  43 of Law 09-08 (to confirm with its current list); declared in the filing.
- Matches the spec's stack and cost (a few euros a month), mature managed Postgres with PostGIS, backups and snapshots,
  Ploi support.
- GDPR applies to the hosting; French customers are covered as they expect.
- Latency from Morocco to Germany or France: roughly 40 to 70 ms, fine for the tap (one request).

**B. Morocco (a Moroccan cloud or data centre provider)**

- No transfer to declare for the database itself; possibly preferred by some customers or public bodies.
- Fewer providers with managed Postgres, PostGIS and automated off-site backups; usually higher cost and more of our own
  operations (patching, backups, failover); to check Ploi compatibility.
- Lower latency for Moroccan customers.

## Criteria

Legal (transfer formalities, the CNDP's position), latency of the tap, monthly cost for the pilot and at 500 businesses,
PostGIS and backups out of the box, an exit path (data portable between hosts), and the sub-processors that stay abroad
either way.

## Decision

Deferred. Until it is made, the CHW-58 hosting criterion stays open and the declaration draft carries both annexes
(docs/privacy/cndp-declaration-draft.fr.md).

## Consequences

- Filing waits for this decision; the pilot (CHW-37) waits for the filing.
- Whichever option wins, backups stay in the same jurisdiction, the privacy notice names the country, and the business
  terms state it.
