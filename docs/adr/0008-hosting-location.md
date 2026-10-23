# 0008. Where customer data is hosted (EU or Morocco)

- Status: accepted (CHW-58): option B, Oracle Cloud in Casablanca
- Date: 2026-10-18; decided 2026-10-23

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

**B, Morocco: Oracle Cloud Infrastructure, region Morocco West (Casablanca, `af-casablanca-1`)**, on a VM of its own.

- **Server**: an Ampere A1 VM (Arm, `VM.Standard.A1.Flex`), about 2 OCPUs and 12 GB of memory, Ubuntu 24.04 (arm64).
  It runs nothing else: the tenancy's other VM (Postiz, 1 OCPU, 6 GB) stays separate, so a fault or breach in one never
  reaches the other's data.
- **Cost**: within Oracle's Always Free allowance (4 OCPUs and 24 GB of A1 a month for the tenancy, of which Postiz uses 1
  and 6). The account moves to Pay As You Go before real data, which keeps those resources free but stops Oracle
  reclaiming an idle Always Free VM.
- **Database**: PostgreSQL with PostGIS on the VM itself (no managed Postgres in the allowance), patched by us.
- **Files** (logos, verification documents): on the VM's disk, or Object Storage in the same region; not Cloudflare R2,
  which would be a transfer.
- **Backups**: nightly, encrypted, to Object Storage in the same region, so they stay in Morocco too (retention and
  restore drill: CHW-59).

Why: with the database in Morocco there is no transfer of it to declare to the CNDP, which unblocks the filing and so the
pilot; "your data stays in Morocco" matters to Moroccan businesses and public bodies; taps are faster for customers in
Morocco. The cost difference with Hetzner (a few euros a month) did not decide it.

Accepted with:

- **No SLA on Always Free**, and Arm capacity is sometimes short in a region. Pay As You Go and our own backups cover
  the first; the exit path covers the rest.
- **More of our own operations** than a host with one-click backups: Postgres, patching and backups are ours. Ploi's
  support for Arm servers is to confirm; otherwise a deploy script.
- **A US provider**: Oracle is subject to US law although the data sits in Morocco. The declaration says so; whether the
  CNDP sees the provider's remote support access as a transfer is to confirm with it.

## Consequences

- The declaration draft keeps the Morocco annex only (docs/privacy/cndp-declaration-draft.fr.md): no transfer of the
  database; the sub-processors abroad (Cloudflare, email, Sentry; later Apple, Google, push services) are still declared.
- Backups stay in Morocco; the privacy notice and the business terms name Morocco and Oracle Cloud.
- The spec's stack (Hetzner + Ploi, R2 storage) is superseded for hosting and file storage.
- Exit path: the app is host-agnostic (everything in `.env`, database drivers, no host-specific service), so moving to
  another host, Hetzner included, is a database dump, the storage folders and a DNS change. Revisit if Oracle's
  availability or support falls short once businesses pay.
