# Verification documents

How the key that encrypts business verification documents (the owner's ID, a proof of address; CHW-145) is set,
checked, kept and changed, and the upload limits the server needs. What is kept and for how long is in
[retention.md](../privacy/retention.md).

## The key

`DOCUMENTS_KEY` is `base64:` and 32 random bytes in the server environment. It encrypts each file (AES-256-GCM,
`App\Support\Verification\DocumentCipher`) before it reaches the private `documents` disk. It is never `APP_KEY`, which
signs cookies, sessions and links, so a leak of one exposes nothing of the other. It is never committed, logged or
sent to a client, and each document stores only the id of the key that encrypted it (`key_id`, 16 hex characters of
its SHA-256).

`.env.example` lists both variables empty:

```dotenv
# Verification documents (CHW-145): their own key, never APP_KEY. Set it with `php artisan punchcard:documents-key`.
DOCUMENTS_KEY=
# After a key change: the old keys, comma-separated, until the files encrypted with them are gone.
DOCUMENTS_PREVIOUS_KEYS=
```

## First setup, once per environment

Staging and production get different keys. On the server, in the app's folder:

```bash
php artisan punchcard:documents-key
php artisan config:cache
```

The command writes `DOCUMENTS_KEY` into `.env` (the Ploi site environment is the same file) and prints no key. A
usable key already set is kept, so running it again never makes documents unreadable; an empty or placeholder value
is replaced. Local setups get theirs from `scripts/bootstrap.sh` and `scripts/bootstrap.ps1`.

Then copy the key from the server's `.env` into the password manager, under the environment's name. Without it every
stored document is unreadable, and a restored backup is no help.

The encryption protects copies made without `.env`: a database dump, a copy of `storage/app/private/documents`, a
mistaken sync of the disk. A backup of the whole volume (Oracle boot or block volume backups) holds `.env` too, so it
holds the key with the files: keep those in the same region (ADR 0008), encrypted, with access limited as for the
server itself, and keep any separate data backup free of `.env`.

## The deploy check

Run `php artisan punchcard:documents:check` in the Ploi deploy script, after `migrate --force` and before the new
release is activated, next to `punchcard:nfc:check` ([stamper-keys.md](stamper-keys.md)). It fails when:

- `DOCUMENTS_KEY` is missing or isn't `base64:` and 32 bytes;
- a key in `DOCUMENTS_PREVIOUS_KEYS` isn't one (named by its place in the list as written, never printed);
- `DOCUMENTS_KEY` or a previous key is `APP_KEY` or one of `APP_PREVIOUS_KEYS`;
- stored documents were encrypted with a key that is in neither variable, so they can't be read. It says how many,
  by key id.

Otherwise the first sign of a bad key is an owner told sending isn't set up, or the platform admin unable to open a
document. It also prints how many documents each previous key still covers. It prints no key material: a key id is the
first 16 hex characters of the SHA-256 of the key's 32 bytes, as stored on each document (`key_id`).

After a lost key (below), run it as `punchcard:documents:check --allow-unreadable` until the unreadable documents are
gone: they are then a warning, and every other failure still stops the deploy.

## Changing the key

Change it when it may have leaked, or when someone who had it leaves.

1. Move the current value to `DOCUMENTS_PREVIOUS_KEYS` (comma-separated, newest first).
2. Set `DOCUMENTS_KEY` to a new key: empty it and run `php artisan punchcard:documents-key`.
3. Run `php artisan config:cache`, then `php artisan punchcard:documents:check`.
4. Store the new key in the password manager; keep the old one there too.

New uploads use the new key; files are not encrypted again, so older ones are read with the previous key. Keep it
until the last file under it is deleted: at most 12 months waiting for a decision, then 3 months after it, so about 15
months. The check prints how many documents each previous key still covers ("Previous key 2: 0 documents"): remove
one only once it is at 0, then run `config:cache` and the check again.

A key that leaked stays dangerous for the files it encrypted, wherever copies of them are. Changing it protects new
uploads only.

## A lost key

If `DOCUMENTS_KEY` is gone from the server and the password manager, the files under it can't be read again, and the
check fails with their count. Set a new key as in the first setup, and add `--allow-unreadable` to the check in the
deploy script. Then in A1 use "Ask for new documents" on each pending business with documents waiting, saying why: the
owners are emailed, send new ones, and the unreadable files go then or 3 months later. Documents already accepted, and
those waiting at a suspended or closed business (which can't be asked), can't be opened any more and go on their own
date: 3 months after the decision, or 12 months after they were sent. Once the check passes without
`--allow-unreadable`, take the option out of the deploy script.

## Upload limits

Documents are up to 10 MB (`DocumentRequest::MAX_KILOBYTES`). Three layers must allow the request:

- **PHP-FPM**: `public/.user.ini` sets `upload_max_filesize = 12M` and `post_max_size = 13M`. PHP-FPM reads it per
  directory, at most every 5 minutes (`user_ini.cache_ttl`).
- **nginx**: refuses bodies over 1 MB by default. Check with `grep -rn client_max_body_size /etc/nginx/`, and if it is
  under 13M add `client_max_body_size 13M;` inside the site's `server { … }` block (Ploi: the site's Nginx
  configuration), then `sudo nginx -t && sudo systemctl reload nginx`.
- **Cloudflare**: up to 100 MB on the free plan, nothing to change.

To confirm, send a PDF of about 9 MB from the verification page. A "413 Request Entity Too Large" means nginx still
refuses it.
