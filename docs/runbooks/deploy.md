# Deploy

How a punchcard environment is set up on Oracle Cloud in Casablanca (ADR 0008) and deployed from GitHub through Ploi
(CHW-296). Written for staging; production repeats it with its own server, keys and names (the end of this page). Every
step that needs a console click or a secret is done by hand. Nothing here goes in the repository except this page.

Staging holds test data only: our own accounts and test businesses, never a real café or customer before the CNDP
filing (CHW-58).

## 1. Before you start

- A domain on Cloudflare, with a name for the environment (`staging.<domain>` below).
- The Oracle tenancy's console, with two-factor on for every user who can sign in (Identity › Users › your user ›
  Enable multi-factor authentication). The tenancy also runs Postiz: nothing below touches it.
- A Ploi account, a GitHub user who can admin `r0b0t007/punchcard`, a Resend account with the domain verified, and the
  password manager, with an entry per environment.
- An SSH key pair on your machine for the server (`ssh-keygen -t ed25519 -C punchcard-staging`).

**Ploi on Arm.** Ploi's documentation lists Ubuntu 24.04 and custom servers, and says nothing about Arm (arm64). Before
building anything else, check it in step 3: if Ploi's installer fails or refuses the server, stop there, note it on
CHW-296, and the deploy moves to a script of our own (ADR 0008, Consequences) in a follow-up. Every other step stays.

## 2. Oracle: compartment, network, VM

In the console, region **Morocco West (Casablanca)**:

1. **Compartment**: Identity › Compartments › Create, `punchcard-staging`. Everything below goes in it.
2. **Network**: Networking › Virtual cloud networks › Start VCN Wizard › "Create VCN with Internet Connectivity",
   name `punchcard-staging-vcn`. It must not peer with Postiz's VCN (no local peering gateway, no DRG).
3. **Security list** of the public subnet: remove the default rule for TCP 22 from `0.0.0.0/0`, keep the default ICMP
   rules (type 3 code 4 from anywhere is what path MTU discovery needs: without it, large responses and uploads can
   hang), then add ingress:
    - TCP 22 from your own IP (`<your ip>/32`), and from Ploi's server addresses while Ploi manages the server
      (Ploi lists them in its documentation and on the server's page);
    - TCP 80 and 443 from each of Cloudflare's IPv4 ranges (<https://www.cloudflare.com/ips-v4>), one rule per range.
      This keeps out direct traffic, but not another Cloudflare account pointed at the VM's IP: the origin secret in
      step 9 closes that, and `TRUSTED_PROXIES=*` in step 6 relies on both.
    - Egress: all, to `0.0.0.0/0` (packages, Resend, GitHub).
4. **VM**: Compute › Instances › Create:
    - name `punchcard-staging`, image **Canonical Ubuntu 24.04** (the aarch64 build is picked with the shape);
    - shape **Ampere › VM.Standard.A1.Flex, 2 OCPUs, 12 GB**. The tenancy's A1 total must stay at or under 4 OCPUs and
      24 GB (Postiz uses 1 and 6), or it is billed;
    - the public subnet above, a public IPv4 address, your SSH public key;
    - boot volume 50 GB (the Always Free storage allowance covers 200 GB in all).

    "Out of capacity" means no Arm host is free in the region right now: try again later, or with 1 OCPU and 6 GB and
    resize afterwards.

5. **The VM's own firewall.** Oracle's Ubuntu images ship iptables rules that reject everything but SSH, on top of the
   security list. On the VM (`ssh ubuntu@<public ip>`):

    ```bash
    sudo iptables -L INPUT --line-numbers   # find the final REJECT rule's number, often 6
    sudo iptables -I INPUT 6 -p tcp --dport 80 -m state --state NEW -j ACCEPT
    sudo iptables -I INPUT 6 -p tcp --dport 443 -m state --state NEW -j ACCEPT
    sudo netfilter-persistent save
    ```

    Insert them before that REJECT rule (use its number in place of 6). Ploi may replace these rules with its own
    firewall (ufw) in step 3: that's checked there.

## 3. Ploi: the server

1. On the VM, become root: `sudo -i`. Ploi installs as root.
2. In Ploi: Servers › Create › **Custom server**. Copy the command with the Copy button and run it as root on the VM.
   Then enter the VM's public IP and the rest of the form in Ploi, and log out of the SSH session so the install isn't
   interrupted.
3. Pick **PHP 8.4**, **nginx**, **PostgreSQL** (17 if offered, as in CI) and **Node 22**. No MySQL, no Redis (ADR
   0005).
4. When the server shows as active, it runs on Arm. If the install fails, see "Ploi on Arm" in step 1.
5. **Firewall after Ploi.** Installing ufw removes `netfilter-persistent`, so the rules saved in step 2 are no longer
   restored at boot and ufw's apply instead. Every time, whether or not you changed anything: `sudo ufw status` must
   list 22, 80 and 443 (add the missing ones in Ploi's Network tab), then `sudo reboot` once and check the VM answers
   on 443 again (`curl -kI https://<public ip>` from an address in the security list, or `/up` after step 9).

PostGIS isn't used yet (the venue map, C7, will use it). Install it now so the database matches CI, with the package
for the installed Postgres version (`psql --version`), for example `sudo apt install postgresql-17-postgis-3`.

## 4. Ploi: the database

Databases › Create: database `punchcard`, its own user with a generated password (into the password manager). It listens
on localhost only: nothing in the security list opens 5432, and it must stay that way.

Since PostgreSQL 15, only the database's owner can create tables in `public`, so the first `migrate` fails if the user
isn't the owner. Check, and set it if needed:

```bash
sudo -u postgres psql -c "\l punchcard"                              # the Owner column
sudo -u postgres psql -c "ALTER DATABASE punchcard OWNER TO <user>;"  # if it isn't <user>
```

## 5. Ploi: the site

1. Sites › Create: domain `staging.<domain>`, web directory `/public`, project type Laravel.
2. Repository: GitHub, `r0b0t007/punchcard`, branch `main`. Turn **"Deploy on push" off**: only the GitHub workflow
   deploys, after CI passes (step 10).
3. Turn **zero-downtime deployment on**. Check that `storage` and `.env` are shared between releases (Ploi's default):
   the logos and the verification documents live in `storage/app`, and must survive every deploy.
4. **nginx**: in the site's Nginx configuration, inside `server { … }`, add `client_max_body_size 13M;` (verification
   documents up to 10 MB, [verification-documents.md](verification-documents.md), Upload limits). Ploi tests and reloads
   nginx on save.

## 6. The environment

In the site's Environment tab (Ploi writes it to the site's `.env`, never to the repository), set:

| Variable                                            | Staging value                                                       |
| --------------------------------------------------- | ------------------------------------------------------------------- |
| `APP_NAME`                                          | `punchcard`                                                         |
| `VITE_APP_NAME`                                     | `${APP_NAME}` (read by `npm run build` for the page titles)         |
| `APP_ENV`                                           | `staging` (production: `production`)                                |
| `APP_DEBUG`                                         | `false`                                                             |
| `APP_URL`                                           | `https://staging.<domain>`                                          |
| `TAP_URL`                                           | `https://staging.<domain>/t` (written into stampers: keep it short) |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`               | `pgsql`, `127.0.0.1`, `5432`                                        |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`         | from step 4                                                         |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` each (ADR 0005)                                          |
| `SESSION_SECURE_COOKIE`                             | `true`                                                              |
| `MAIL_MAILER`                                       | `resend`                                                            |
| `RESEND_API_KEY`                                    | a Resend key for this environment only                              |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`               | an address on the verified domain, `punchcard`                      |
| `TRUSTED_PROXIES`                                   | `*` (only because step 2 lets Cloudflare alone reach the origin)    |
| `LOG_CHANNEL`, `LOG_LEVEL`                          | `daily`, `info`                                                     |
| `APP_KEY`, `DOCUMENTS_KEY`                          | each its own, generated below                                       |
| `NFC_SUN_MASTER_KEY`                                | its own, generated below                                            |
| `NFC_SUN_KEY_VERSION`                               | `1`                                                                 |
| `PASSKEYS_USER_HANDLE_SECRET`                       | its own, generated below                                            |

Every value goes in through this tab, before the first deploy: there is no release yet to run `artisan` in, and the
first deploy's checks need the keys. Generate the four keys on the server with plain PHP (installed by Ploi in step 3),
one at a time:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"   # APP_KEY, then again for DOCUMENTS_KEY
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"                   # NFC_SUN_MASTER_KEY: 32 hex characters
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"                   # PASSKEYS_USER_HANDLE_SECRET
```

`APP_KEY` and `DOCUMENTS_KEY` are two different runs, never the same value (`punchcard:documents:check` refuses that).
Run these on the server, not through a chat or a shared terminal, and copy each value straight into the tab and the
password manager. Reload the tab before editing it, and edit it only there: saving a copy loaded before another change
to `.env` writes the old text back.

Staging and production never share a key. `PASSKEYS_USER_HANDLE_SECRET` is set on its own so that changing `APP_KEY`
later doesn't break every passkey. `APP_ENV=staging` keeps the fake tap commands off (they run in `local` and
`testing` only).

**Changing a value later.** The deploy script caches the config (`config:cache`), so after the first deploy a change
in this tab does nothing until the config is cached again: deploy (Ploi's "Deploy now"), or in
`/home/ploi/staging.<domain>/current` run `php artisan config:cache && php artisan queue:restart`. A `VITE_` variable
only changes with a new build, so it needs a deploy.

## 7. The deploy script

Ploi's zero-downtime script has its own lines at the top and bottom (creating the release, then activating it): keep
them, and put these between them:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
php artisan punchcard:nfc:check
php artisan punchcard:documents:check

npm ci
npm run build

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link
```

and this one last, after Ploi's line that activates the release:

```bash
php artisan queue:restart
```

The two checks fail the deploy, before the new release is activated, on a missing, malformed or shared key
([stamper-keys.md](stamper-keys.md), [verification-documents.md](verification-documents.md)). They run before
`migrate`, so a deploy they reject leaves the schema as the live release expects it; on a first deploy, with no
documents table yet, `documents:check` checks the keys only. `npm run build` needs PHP and the `.env` (Wayfinder
generates the typed routes during the build), so it comes after `composer install`. `queue:restart` comes after the
switch, so the worker Supervisor starts again runs the new release's code, not the old one's.

`migrate` runs while the old release still serves, and a later step can still stop the deploy, so a migration must
keep the old release working: add a column or table in one release, use it in the next, drop what's unused in a third.

Run it once by hand with "Deploy now" in Ploi and watch the log. Turn on Ploi's notifications for failed deploys (the
site's Notifications, by email): the GitHub workflow only proves the deploy was triggered and the site still answers.

## 8. Queue worker and scheduler

- **Queue**: the site's Queue tab, a worker on connection `database`, queue `default`, 1 process, `--tries=3`. Ploi
  runs it under Supervisor and `queue:restart` at the end of the deploy script reloads it with the new code.
- **Scheduler**: the server's Cron jobs, every minute: `php /home/ploi/staging.<domain>/current/artisan schedule:run`
  (the site's path as Ploi shows it, always with `current/`: with zero-downtime, `artisan` lives only in the active
  release). Check its log after a few minutes: a wrong path fails silently, and the retention jobs never run. It runs
  the tap log prune, the expired pending taps, the inactivity warnings and sweep, and the documents sweep
  (`routes/console.php`).

## 9. Cloudflare and HTTPS

1. DNS: an `A` record `staging` to the VM's public IP, **proxied** (orange cloud).
2. SSL/TLS › Overview: **Full (strict)**.
3. SSL/TLS › Origin Server › Create certificate for `staging.<domain>`. In Ploi, the site's SSL tab › Custom
   certificate, paste the certificate and its private key. The private key goes into Ploi and the password manager
   only. An Origin CA certificate lasts up to 15 years, so there's nothing to renew; Let's Encrypt would also work (its
   HTTP check passes through Cloudflare, or use its DNS check), with renewals to watch.
4. **Origin secret.** The security list lets in every Cloudflare address, including other accounts' zones pointed at
   the VM's IP, which would skip our zone's rules and rate limits. So our zone adds a secret header, and nginx refuses
   requests without it:
    - generate a value on the server: `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"` (into the password manager);
    - Cloudflare › Rules › Transform Rules › Modify Request Header › Create: when the hostname equals
      `staging.<domain>`, **Set static** header `X-Origin-Secret` to that value;
    - in the site's Nginx configuration, inside `server { … }`:

        ```nginx
        if ($http_x_origin_secret != "<that value>") {
            return 403;
        }
        ```

    - check: `curl -sk -o /dev/null -w "%{http_code}\n" -H "Host: staging.<domain>" https://<public ip>/up` from an
      address in the security list answers 403, and `https://staging.<domain>/up` answers 200.

`https://staging.<domain>/up` should now answer 200.

## 10. GitHub: deploy from main

In the repository's Settings (admin only):

1. Environments › `staging`: no reviewer needed.
2. Secrets and variables › Actions:
    - secret `PLOI_DEPLOY_HOOK_STAGING`: the site's deploy webhook URL (Ploi › site › Deployment › Deploy webhook). It
      deploys whoever calls it, so it goes nowhere else;
    - variable `STAGING_URL`: `https://staging.<domain>`.
3. Variable `DEPLOY_ENABLED`: `true`.

Set the secret in the GitHub page itself, not with `gh secret set` in a shared terminal. From then on, every merge to
`main` whose CI passes calls the webhook, then waits for `/up` (`.github/workflows/deploy.yml`). A deploy can also be
started by hand: Actions › deploy › Run workflow › staging.

A green workflow doesn't mean the new code is live. Ploi answers the webhook at once and deploys after; with
zero-downtime, the old release keeps answering `/up` while the new one builds, and goes on answering if a check stops
the deploy. Ploi's deploy log and its failure notification (step 7) are what tell you a deploy failed.

## 11. First admin and smoke test

1. **Mail first.** Register on `https://staging.<domain>` with your own address: signing up needs the verification
   email, so if it doesn't arrive within a minute, fix mail (`RESEND_API_KEY`, the sender domain in Resend, the
   config cache) before anything else. `storage/logs` in the release folder has the error.
2. Make that account the platform admin: turn on two-factor in its settings, then in
   `/home/ploi/staging.<domain>/current` run `php artisan punchcard:admin <your email>` (the `admin` role, ADR 0006;
   Filament at `/admin`).
3. Smoke test, all with test data:
    - sign up as a business owner, finish onboarding, and set the card to 5 stamps, a cooldown of 0 minutes and a
      daily cap of 5 or more (the defaults, 20 minutes and 5 a day, apply to manual stamps too);
    - add stamps from the counter (manual stamps) to a test customer, until a reward unlocks;
    - send an ID and a proof of address of about 9 MB each from the verification page (a 413 means nginx still refuses
      it), and open them in A1 as the admin.
4. Real taps need a provisioned stamper (CHW-16) registered with `punchcard:stamper:register`, with this environment's
   `NFC_SUN_MASTER_KEY` and `TAP_URL`.

## Production

The same steps with:

- its own compartment, VCN, security list and VM (`punchcard-production`). The Always Free A1 total is 4 OCPUs and
  24 GB for the whole tenancy: with Postiz (1, 6) and staging (2, 12), 1 OCPU and 6 GB are left. Either production
  takes those (1 OCPU, 6 GB), or staging shrinks to 1 OCPU and 6 GB first and production gets 2 and 12, or the
  difference is billed. The account moves to Pay As You Go before real data either way (ADR 0008);
- its own database password, keys (`APP_KEY`, `DOCUMENTS_KEY`, `NFC_SUN_MASTER_KEY`, `PASSKEYS_USER_HANDLE_SECRET`),
  Resend key and origin certificate, each in the password manager under production;
- `APP_ENV=production` (destructive database commands are refused, and passwords need 12 characters and more);
- GitHub: environment `production` with a required reviewer, secret `PLOI_DEPLOY_HOOK_PRODUCTION`, variable
  `PRODUCTION_URL`. It deploys when a GitHub release is published and the reviewer approves.

Backups, the restore test, Sentry and the uptime check come before the pilot (CHW-59, CHW-37).
