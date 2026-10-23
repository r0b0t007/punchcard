# 0008. Where customer data is hosted

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

- **Server**: an Ampere A1 VM (Arm, `VM.Standard.A1.Flex`), about 2 OCPUs and 12 GB of memory, Ubuntu 24.04 (arm64),
  on Oracle's shared hardware. It runs nothing else. The tenancy's other VM (Postiz, 1 OCPU, 6 GB) stays apart: punchcard
  gets its own compartment, its own network (VCN, security lists, no route to Postiz) and IAM policies scoped to that
  compartment, so a fault or breach in Postiz doesn't reach punchcard's VM, disks or backups. What both still share is
  the tenancy itself: the console and API credentials that administer it, so those get two-factor and no day-to-day use.
- **Cost**: Oracle's Always Free allowance (4 OCPUs and 24 GB of A1 a month for the tenancy, of which Postiz uses 1 and 6)
  applies only in the tenancy's home region, which is Morocco West (Casablanca), so punchcard's VM is free as long as
  the two VMs together stay within 4 OCPUs and 24 GB. The backups count against the separate Always Free storage
  allowance (Object Storage, to check in the console): tiny at the pilot, but 30 nightly full copies grow with the
  logos and documents, so watch the size and switch to incremental copies before they pass the allowance, or they
  are billed. The account moves to Pay As You Go before real data, which keeps free resources free but stops Oracle
  reclaiming an idle Always Free VM.
- **Database**: PostgreSQL with PostGIS on the VM itself (no managed Postgres in the allowance), patched by us.
- **Files** (logos, verification documents): on the VM's disk, or Object Storage in the same region; not Cloudflare R2,
  which would be a transfer.
- **Backups**: nightly, encrypted, to Object Storage in the same region, so they stay in Morocco too: the database dump
  and both storage folders (`storage/app/public` and the already encrypted `storage/app/documents`). Losing the VM then
  loses no logo or document. Backups are kept 30 days, so deleted data (closed accounts, documents past their date)
  is gone from them 30 days after its deletion. A restore applies the deletions made since the backup again before
  the app serves anyone (retention.md, "After a restore"; restore drill: CHW-59).

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
  database; the sub-processors abroad (Cloudflare; Resend for email, United States; Sentry, EU region in Germany;
  later Apple, Google, push services) are still declared.
- Backups stay in Morocco; the privacy notice and the business terms name Morocco and Oracle Cloud.
- The spec's stack (Hetzner, R2 storage) is superseded for hosting and file storage. Ploi stays the deploy tool (the
  deploy workflow and the stamper-keys runbook use it) if it manages Arm servers; if not, those move to a deploy script.
- Cloudflare stays in front: all traffic passes through it abroad, decrypted, without being stored as a database. The
  declaration, the privacy notice and the business terms say so.
- Exit path: the app is host-agnostic (everything in `.env`, database drivers, no host-specific service), so moving to
  another host, Hetzner included, is a database dump, the storage folders and a DNS change. Revisit if Oracle's
  availability or support falls short once businesses pay.
