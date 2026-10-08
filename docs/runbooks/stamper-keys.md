# Stamper keys

How NTAG 424 DNA stamper keys are derived, stored and rotated. Tag provisioning steps (writing the NDEF
template and SDM settings with a reader) are in the provisioning runbook from CHW-16.

## One secret, derived keys

The only secret is `NFC_SUN_MASTER_KEY` (32 hex characters, AES-128) in the server environment. It is never
committed, logged or sent to a client. Every tag key is derived from it with NXP AN10922 AES-128
diversification (`App\Support\Nfc\KeyDiversifier`), so the database stores no key material, only each
tag's `key_version` (`nfc_tags`).

| Tag key | Purpose                                              | Derived from                                   | Scope       |
| ------- | ---------------------------------------------------- | ---------------------------------------------- | ----------- |
| 0       | Application master key: changes keys and settings    | key number, tag UID, `punchcard`, tag version  | Per tag     |
| 1       | SDMMetaReadKey: decrypts `e` (UID + counter)         | key number, `punchcard`, `NFC_SUN_KEY_VERSION` | System-wide |
| 2       | SDMFileReadKey: signs `c`                            | key number, tag UID, `punchcard`, tag version  | Per tag     |
| 3, 4    | Unused: set to derived per-tag values, never default | key number, tag UID, `punchcard`, tag version  | Per tag     |

Key 1 has to be system-wide: the UID is encrypted inside `e`, so the server cannot know which tag tapped
before decrypting. A leaked key 1 lets someone read UIDs and counters (link taps), but it cannot produce a
valid `c`. Authenticity rests on key 2, which is unique per tag.

Generate a master key once per environment, for example `php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"`,
and store it in the server's environment (Ploi site environment), never in the repository. Staging and
production use different master keys. Run `php artisan punchcard:nfc:check` in the Ploi deploy script, before the new release is activated (CHW-37): it fails on a missing or malformed key, a
bad `NFC_SUN_KEY_VERSION` or a published test key, which would otherwise only show as a 500 on the first tap.

**Open decision for CHW-16:** keys 0, 3 and 4 are only needed while provisioning, but today they come from the
same master the web server holds, so a leak of the server environment also exposes key 0. With key 0, someone
near a tag can rewrite its URL (phishing) or lock us out of it. If provisioning runs off the server, derive keys
0, 3 and 4 from a separate admin master (for example `NFC_TAG_ADMIN_MASTER_KEY`) that only the provisioning
tool loads. Never send key 0 to a browser.

## Registering a tag

Provision the tag first (keys from `key_version` 1, CHW-16), then register and assign it:
`php artisan punchcard:stamper:register <uid> <business slug or id> [--location=<id>] [--label=<name>]`.
A new tag starts at key version 1 and counter 0. A known tag that is free again keeps both. The uid is read as
the reader prints it (spaces, colons, either case). The commands never print key material.

## Changing keys on a tag (applies to every rotation below)

- ChangeKey needs the tag's **current** key 0 to authenticate and, for keys 1 to 4, the current value of the
  key being replaced. Both come from the **old** master and version, so keep them until the tag is done.
- Change keys 1 to 4 first and **key 0 last**: changing key 0 ends the authenticated session.
- If a run stops partway, some keys are old and some new. Retry: try each key with the old value, then the new
  one, and finish the ones still old. Only record the tag as re-provisioned once every key has its new value.

## Rotating one stamper's keys

Do this when a tag is suspected copied.

The tag's uid, `key_version` and `last_counter` live on `nfc_tags` (platform state); a stamper only assigns
the tag to a business and location. Database triggers keep a tag from being deleted and its counter and key
version from going back.

1. Disable the tag's stamper, so it rejects every tap while you work: `php artisan punchcard:stamper:disable <uid>`.
   Note whether the business had already disabled it, so step 4 does not turn on a stamper they paused.
2. Re-provision the tag: keys 2, 3 and 4, then key 0, all derived from `key_version + 1`, authenticating with
   the current version's key 0.
3. Only after every key has changed, record the version the tag now has:
   `php artisan punchcard:tag:rekeyed <uid> <new version>`. It asks you to confirm the keys changed, then moves
   `key_version` from the version before it, so running it again (after a dropped session, say) is refused
   instead of moving the tag past the keys it holds. It keeps `last_counter`: the tag's read counter keeps
   counting, and the old value still blocks replays. It refuses while the stamper is still enabled.
4. Re-enable the stamper (`php artisan punchcard:stamper:enable <uid>`) and test one tap.

Every URL the tag produced before step 2 now fails as `bad_mac`, because its `c` was signed with the old key 2.
The meta key is unchanged, so those URLs still decrypt.

**Moving a tag to another business or location** needs no new keys:
`php artisan punchcard:stamper:move <uid> <business slug or id> [--location=<id>] [--label=<name>]` ends its
stamper's assignment (`unassigned_at`, one-way) and assigns the tag again (a new stamper) in one transaction.
The tag keeps its counter, so URLs from the old site stay replays, and the old business can never reclaim the
tag by re-enabling its stamper. Within a business the stamper keeps its label and status; at another business
it starts active and unlabelled.

A **lost or stolen** tag cannot be re-provisioned: retire it with `php artisan punchcard:tag:retire <uid>`
(`nfc_tags.retired_at`, one-way; its assignment ends too) and register a replacement tag.

## Rotating the meta key (hard cutover)

Decision 2026-10-02: there is one live meta key version. Rotating it re-provisions key 1 on **every** tag
(authenticating with each tag's key 0 and supplying its old key 1, both from the old version), and there is an
outage window either way: tags re-provisioned before `NFC_SUN_KEY_VERSION` changes fail until it changes, and
tags not yet done fail after it. Rotate only if key 1 is known to be exposed, as a scheduled visit to every
location. A gradual rollover (several live versions tried in turn) is deliberately not supported.

While tags and the server are out of step, taps fail as `malformed`. About 1 in 256 decrypts to a valid-looking
tag byte by chance and is recorded as `unknown_tag` instead; both are expected during a cutover.

## Rotating the master key

A leaked master key exposes every tag key: someone near a tag can rewrite it or lock us out, and anyone can
forge taps. Generate a new master, but keep the old one available to the provisioning tool until every tag has
been re-keyed. Swapping `NFC_SUN_MASTER_KEY` first would leave untouched tags with a key 0 nobody can derive,
and those tags would have to be physically replaced. Re-provision every tag (all keys, key 0 last), then switch
the server to the new master and restart queue workers and other long-running processes, which keep the old
master until they restart. Expect the same outage window as a meta key rotation.

## Never

- Log or print `NFC_SUN_MASTER_KEY`, derived keys, `e` or `c`. Functions that take them mark the parameters
  `#[\SensitiveParameter]`, and `KeyDiversifier` keeps the master key wrapped so dumps never show it.
- Change the derivation layout (key number, UID, `punchcard`, version). Every provisioned tag depends on it;
  the golden keys in `tests/Unit/Nfc/KeyDiversifierTest.php` catch an accidental change.
