# Deploying SchoolPilot to cPanel/WHM

This is the runbook for a **VPS or dedicated server with WHM root access**.
It is not written for shared cPanel hosting — see [Why not shared hosting](#why-not-shared-hosting).

The reference deployment is `docker-compose.yml` plus
`docker/nginx/conf.d/schoolpilot.conf`. This directory is the same
architecture rebuilt on Apache and systemd. When the two disagree, the nginx
config is the one that documents the intent.

Go-live prerequisites that are *not* deployment steps — live payment keys,
2FA enrolment order, NDPA documentation — live in [`LAUNCH.md`](../../LAUNCH.md) §1.
Work through this file first, then that checklist.

---

## The shape of it

```
                    Apache (cPanel vhost, TLS terminates here)
                                     |
             +-----------------------+-----------------------+
             |                                               |
      /api/  /storage/                                 everything else
             |                                               |
      PHP 8.2 (ea-php82)                          Node -> 127.0.0.1:3000
   /home/schoolpilot/current/backend/public       Next.js standalone server
             |                                    (schoolpilot-web.service)
             |
   +---------+---------+          queue worker: schoolpilot-worker.service
Postgres 16        Redis 7        scheduler:    cron, every minute
(not cPanel-managed — not in WHM backups)
```

**One hostname serves both applications.** That is load-bearing, not
cosmetic. The web portal's session cookie is httpOnly and same-origin. Moving
the API to `api.schoolpilot.ng` breaks authentication and forces you into
credentialed CORS or into putting the token somewhere JavaScript can read it.

---

## 1. One-time server preparation

### 1.1 PHP

In **WHM → EasyApache 4**, build a profile with `ea-php82` and these
extensions. The app will not boot without the first three.

| Extension | Needed for |
|---|---|
| `pdo_pgsql`, `pgsql` | Postgres. Nothing works without it. |
| `gd` | ID-card and report-card image composition (`ext-gd` in composer.json) |
| `dom` | PDF generation via dompdf (`ext-dom`) |
| `mbstring`, `bcmath`, `zip`, `intl`, `curl`, `openssl` | Framework and payment SDKs |
| `redis` | Optional — see §1.4 |

Then in **MultiPHP INI Editor**, for the `schoolpilot` account:

```ini
memory_limit = 512M          ; broadsheet compilation across a whole school
max_execution_time = 60      ; matches ProxyTimeout in apache/schoolpilot.conf
upload_max_filesize = 25M    ; CBT question images, bulk student CSV imports
post_max_size = 26M          ; must exceed upload_max_filesize
```

`LimitRequestBody` in the vhost and `upload_max_filesize` here must agree.
Raise only one and uploads fail at the other layer with a much less obvious
error.

### 1.2 The cPanel account

**WHM → Create a New Account**: username `schoolpilot`, domain
`schoolpilot.ng`, PHP 8.2, and enable **SSH access** and **shell access**
(the deploy scripts need them).

Then create the directory layout:

```bash
sudo -u schoolpilot mkdir -p /home/schoolpilot/{releases,shared/storage,logs,backups,uploads}
sudo -u schoolpilot cp -r /path/to/repo/backend/storage/. /home/schoolpilot/shared/storage/
sudo -u schoolpilot chmod -R 775 /home/schoolpilot/shared/storage
```

`shared/storage` holds generated report-card PDFs and ID-card runs. It lives
outside the release directories so a deploy does not throw away files that
schools already hold download links to.

### 1.3 Postgres

cPanel does not manage Postgres. Install it directly:

```bash
dnf install -y postgresql16-server postgresql16-contrib
/usr/pgsql-16/bin/postgresql-16-setup initdb
systemctl enable --now postgresql-16

sudo -u postgres psql <<'SQL'
CREATE ROLE schoolpilot_user LOGIN PASSWORD 'CHANGE_ME';
CREATE DATABASE schoolpilot OWNER schoolpilot_user;
SQL
```

Leave it listening on `127.0.0.1` only. There is no reason for the database
port to be reachable from outside the box, and a Postgres open to the internet
with a password-only role is how student records leak.

> **Postgres is required, not preferred.**
> [`UserManagementController.php:59-60`](../../backend/app/Http/Controllers/Api/V1/UserManagementController.php#L59-L60)
> uses `ilike`, which MySQL does not implement. Switching to MySQL would break
> admin user search at runtime, not at deploy time.

### 1.4 Redis

```bash
dnf install -y redis && systemctl enable --now redis
/opt/cpanel/ea-php82/root/usr/bin/php -m | grep redis
```

If that last line prints nothing, install `redis` for ea-php82 from
**WHM → Software → Module Installers → PECL**. If you cannot get it, set
`CACHE_STORE=database` and `QUEUE_CONNECTION=database` in the env file. Those
are the application defaults; the platform works, just slower under load.
Sessions stay on `database` either way — a Redis restart should not log every
teacher out mid-lesson.

### 1.5 Node

```bash
curl -fsSL https://rpm.nodesource.com/setup_20.x | bash - && dnf install -y nodejs
```

Do **not** use cPanel's "Setup Node.js App". It wraps the process in Passenger
with its own lifecycle, which fights the `standalone` output that
[`next.config.ts`](../../web/next.config.ts) produces. A plain systemd unit is
simpler and easier to reason about at 7am on exam day.

### 1.6 DNS and the wildcard subdomain

SchoolPilot resolves the school from the request host, so every tenant is a
subdomain.

1. DNS: an `A` record for `schoolpilot.ng` **and** a wildcard `*.schoolpilot.ng`,
   both pointing at this server.
2. **cPanel → Domains → Create A New Domain**: `*.schoolpilot.ng`, with the
   same document root as the main domain (set in §1.7).

### 1.7 Document root

Point both the main domain and the wildcard at the release symlink, so a
deploy never has to touch Apache:

```
# /var/cpanel/userdata/schoolpilot/schoolpilot.ng
#   documentroot: /home/schoolpilot/current/backend/public
# ...and the same in the _SSL variant, and in both files for *.schoolpilot.ng
```

```bash
/scripts/rebuildhttpdconf && systemctl restart httpd
```

Only `backend/public` is ever web-reachable. `.env`, `storage/` and `vendor/`
sit above it and stay unreachable — which is why the document root is not
`public_html`.

### 1.8 Wildcard TLS — **plan for this before go-live**

cPanel AutoSSL **cannot issue wildcard certificates.** It validates over HTTP,
and a wildcard requires DNS-01. Without a wildcard cert, every tenant
subdomain shows a certificate warning from the first school onwards.

Two options:

- **`acme.sh` with DNS-01** against your DNS provider's API:
  ```bash
  acme.sh --issue -d schoolpilot.ng -d '*.schoolpilot.ng' --dns dns_cf
  acme.sh --deploy -d schoolpilot.ng --deploy-hook cpanel_uapi
  ```
- **A purchased wildcard certificate**, installed via WHM → SSL/TLS →
  Install an SSL Certificate.

Either way, confirm auto-renewal actually runs. An expired wildcard takes the
entire platform offline for every school at once.

### 1.9 Apache vhost include

```bash
for tree in std ssl; do
  for dom in schoolpilot.ng "*.schoolpilot.ng"; do
    install -D -m 0644 apache/schoolpilot.conf \
      "/etc/apache2/conf.d/userdata/$tree/2_4/schoolpilot/$dom/schoolpilot.conf"
  done
done
/scripts/ensure_vhost_includes --user=schoolpilot
/scripts/rebuildhttpdconf && systemctl restart httpd
```

Never edit `httpd.conf` directly. cPanel regenerates it on every update, and
the config disappears without warning — usually noticed when the whole admin
portal starts returning Laravel 404s because `/` stopped being proxied.

Requires `mod_proxy`, `mod_proxy_http` and `mod_headers` in the EasyApache
profile.

### 1.10 Services and cron

```bash
install -m 0644 systemd/*.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable schoolpilot-web schoolpilot-worker

crontab -u schoolpilot cron/schoolpilot.cron
```

Both units matter more than they look:

- **No worker** → report cards, ID-card runs, exports and every SMS/WhatsApp
  notification queue up and never run. The API returns success, so nothing in
  the logs looks wrong; the school just sees a spinner that never resolves.
- **No scheduler** → `cbt:expire-attempts` never runs, so a CBT exam never
  closes on its own.

### 1.11 Environment file

```bash
cp .env.production.example /home/schoolpilot/shared/backend.env
chown schoolpilot:schoolpilot /home/schoolpilot/shared/backend.env
chmod 600 /home/schoolpilot/shared/backend.env
$EDITOR /home/schoolpilot/shared/backend.env
```

Read the `APP_KEY` comment in that file before filling it in. If this platform
already holds school data anywhere, the key must be **carried over**, not
regenerated — it decrypts student medical fields, and a new key makes those
columns unreadable permanently.

---

## 2. Deploying

### First deploy

```bash
# your machine
./deploy/cpanel/build-release.sh
scp dist/schoolpilot-<sha>.tar.gz schoolpilot@schoolpilot.ng:~/uploads/

# the server, as root
deploy/cpanel/deploy.sh /home/schoolpilot/uploads/schoolpilot-<sha>.tar.gz
```

Builds happen on your machine, never on the server. `composer install` and
`next build` together need more RAM than a school-hours box has spare, and a
build that dies halfway leaves a half-populated `vendor/` under the live
document root.

`deploy.sh` takes a database backup, puts the site in maintenance mode with a
bypass URL it prints for you, migrates, warms caches, flips the `current`
symlink, restarts both services, health-checks through the public hostname,
and only then lifts maintenance mode. If either health check fails it stops
and **stays** in maintenance rather than opening a broken portal to schools.

### Routine deploys

Identical — same two commands. Releases unpack side by side under
`releases/`, and `current` is a symlink, so the switch is atomic and the last
five releases stay on disk.

### Rollback

```bash
ln -sfn $(ls -1dt /home/schoolpilot/releases/*/ | sed -n 2p) /home/schoolpilot/current
systemctl restart schoolpilot-web schoolpilot-worker
```

This does **not** undo migrations. If the bad release changed the schema in a
way the previous one cannot read, restore the pre-deploy dump
(`/home/schoolpilot/backups/`) as well.

---

## 3. Verify before the first school

```bash
systemctl status schoolpilot-web schoolpilot-worker   # both active
crontab -u schoolpilot -l                             # scheduler present

curl -sI https://schoolpilot.ng/api/v1/health         # 200, from Laravel
curl -sI https://demo.schoolpilot.ng/                 # 200, valid cert on a subdomain

# The tenant Host header survives the proxy — if this resolves to the wrong
# school, ProxyPreserveHost is off and every tenant has collapsed into one.
curl -s https://demo.schoolpilot.ng/api/v1/health

# A queued job actually completes end to end.
sudo -u schoolpilot php /home/schoolpilot/current/backend/artisan queue:work --once

/home/schoolpilot/current/deploy/cpanel/backup-db.sh  # and restore it once
```

Then work through [`LAUNCH.md`](../../LAUNCH.md) §1 — live payment keys, one
real SMS and one real WhatsApp message, AI budget caps, admin 2FA enrolment,
and only last `AUTH_REQUIRE_ADMIN_2FA=true`.

---

## 4. When it goes wrong

| Symptom | Cause |
|---|---|
| Admin portal 404s from Laravel | The `ProxyPass /` catch-all is gone — cPanel rebuilt `httpd.conf` over it. Re-run §1.9. |
| Every school shows the same tenant's data | `ProxyPreserveHost Off`, or a proxy hop rewriting `Host`. |
| Login succeeds then immediately bounces | `SESSION_DOMAIN` set, or `FORCE_HTTPS` unset so the cookie is not marked Secure. |
| Report cards stuck "generating" | Worker is dead. `systemctl status schoolpilot-worker`. |
| CBT exams never auto-submit | Scheduler cron missing. |
| Cert warning on tenant subdomains only | No wildcard cert — AutoSSL cannot issue one. §1.8. |
| 500 right after deploy, fine before | Stale `config:cache` against old paths. `php artisan config:clear` then redeploy. |
| Uploads fail at ~2MB | `upload_max_filesize` not raised in MultiPHP INI Editor. §1.1. |

---

## Why not shared hosting

For the record, in case this question comes back. Shared cPanel cannot run
this platform: no Postgres (and `ilike` rules out MySQL), no Redis, no
long-lived queue worker, no systemd, no Node process for the standalone
Next.js build, and no wildcard TLS. Each of those is individually a blocker.
