# Deploying device provisioning

Copy-paste steps, in the order that keeps the site working at every point.
Companion to `SERVER_GIT_COMMANDS.md`.

**Why the order matters:** `admin/provisions.php` reads a database view and
`api/provision.php` reads a private key. Pull the code first and both are
broken until the other two steps are done. Do the database and the key first
— neither touches anything the current code uses — and the pull lands on a
server that is already ready for it.

---

## 0. Before anything ships: the key

`grant_key.pem` is the single private key every grant is signed with, and
`license_key.h` in the firmware holds its public half. **Once a device ships
with that firmware, this key can never change** — a new key makes every
grant ever issued unverifiable, on every board in the field.

So, once, now:

- Decide this key is *the* key (the one `tools/grant_sign.py keygen` made and
  the firmware already carries), or generate a fresh one on a machine you
  trust with `grant_sign.py keygen --force` and rebuild the firmware. Either
  is fine today; only the first is fine after a vendor has firmware.
- **Back it up offline** — a USB stick in a drawer. Losing it means no board
  can ever be re-provisioned, including repairs.
- It goes on the server **outside `public_html`**: `/home/sgkk4203/grant_key.pem`.

---

## 1. Local: commit and push

```bash
cd C:/AL/Private/orca_backend
git status                       # 5 modified, 10 new - all provisioning
git add -A
git commit -m "Device provisioning"
git push origin main
```

(Or on a `feature/provisioning` branch merged to `main` — the server only
ever pulls `main`.)

---

## 2. Server: check PHP can sign

```bash
ssh sgkk4203@<host>
php -v                            # 7.1 or newer
php -m | grep -i openssl          # must print: openssl
```

No `openssl` in that list means the host's CLI PHP differs from the web PHP.
Try `php -i | grep "Loaded Configuration"` and the hosting panel's PHP
selector; the web PHP almost always has it.

---

## 3. Server: the key

Upload `grant_key.pem` to `/home/sgkk4203/` — cPanel File Manager, or from
your machine:

```bash
scp C:/AL/Private/DL_Main_CCode/tools/grant_key.pem sgkk4203@<host>:/home/sgkk4203/grant_key.pem
```

Then on the server:

```bash
chmod 600 /home/sgkk4203/grant_key.pem
ls -la /home/sgkk4203/grant_key.pem            # -rw------- and NOT under public_html
```

`.env` — the default already resolves to this path, but say it explicitly:

```bash
cd /home/sgkk4203/public_html/orca_backend
echo 'GRANT_PRIVATE_KEY_PATH=/home/sgkk4203/grant_key.pem' >> .env
```

---

## 4. Server: the database

Additive only - a new `provisions` table and one view; nothing the current
code reads changes.

```bash
cd /home/sgkk4203/public_html/orca_backend
git fetch origin
git show origin/main:database/migration_provisioning.sql > /tmp/m1.sql
git show origin/main:database/migration_provisioning_simplify.sql > /tmp/m2.sql
mysql -u <DB_USER> -p <DB_NAME> < /tmp/m1.sql
mysql -u <DB_USER> -p <DB_NAME> < /tmp/m2.sql
```

Both, in that order. The first builds the table; the second takes
provisioning back off `companies`, because companies are CUSTOMERS and the
vendor is a different thing. (Or paste each file into phpMyAdmin -> SQL.)

If the first stops at `CREATE OR REPLACE VIEW` with a privilege error, the DB
user lacks `CREATE VIEW`: grant it in the hosting panel and re-run that one
statement. Everything else works without the view; the admin page says so
rather than failing.

## 5. Server: pull the code

```bash
cd /home/sgkk4203/public_html/orca_backend && git pull origin main
```

---

## 6. Server: prove the signer

```bash
cd /home/sgkk4203/public_html/orca_backend
php tools/grant_pubkey.php
```

Expect:

```
Key file: /home/sgkk4203/grant_key.pem
static const uint8_t gArLicensePubKey[LICENSE_KEY_LEN] = { ... }
Self-test: signed 168 chars, verify OK
```

The first row of that array must match the first row in the firmware's
`license_key.h`. To have the tool check instead of your eyes, upload the `.h`
and pass its path: `php tools/grant_pubkey.php /tmp/license_key.h` → `MATCH`.

---

## 7. Server: switch provisioning on

One vendor - us - so there is no vendor table and nothing to create. Three
lines in `.env`:

```bash
cd /home/sgkk4203/public_html/orca_backend
cat >> .env <<'EOF'
PROVISION_KEY=9785d4e5557edd6b31b70281974d4ecf41d12d4561f2b2d03c82426424188aba
PROVISION_SERIAL_PREFIX=A
PROVISION_VENDOR_NAME=Almas Electronic
EOF
```

`PROVISION_KEY` must be the value Orca ships with (`provision.py`,
`DEFAULT_KEY`) - that is the whole handshake. It is not a strong secret,
because the tool carries it; what it does is stop the endpoint answering the
open internet. Leave it empty and provisioning is off: the API answers 503
and says so.

`PROVISION_SERIAL_PREFIX=A` gives `A0000001`, `A0000002` ... - the shape every
existing serial already has. The next number is derived from the highest one
issued, so there is no counter to keep in step.

When there is a second vendor, this is the point that grows a `vendors`
table; the rows written until then all belong to vendor "us".

## 8. Prove it end to end

Status — no side effects:

```bash
curl -s -H "Authorization: Bearer <KEY>" \
  https://myco-grid.com/orca_backend/api/provision_status.php
```

Expect `"success":true` … `"signer_ready":true`. If `signer_ready` is false,
the error text says which of steps 2, 3 or 6 to revisit.

A real provision, with the dev board's UID:

```bash
curl -s -X POST -H "Authorization: Bearer $PROVISION_KEY" -H "Content-Type: application/json" \
  -d '{"uid":"203530473932501800370043","tool":"curl"}' \
  https://myco-grid.com/orca_backend/api/provision.php
```

Expect `201`, a `serial_number`, and a 168-character `grant`. Run it a second
time: `200`, the **same** serial and grant, `"reissued":true`, and the count
unchanged.
That second run is the whole idempotency guarantee, so it is worth seeing once.

Then on a board: `license <grant>` → `Licence installed. Serial: A0000001`.

Admin → Provisioning shows the row and its timestamp, and the count goes up
by one.

---

## If something is wrong

| Symptom | Look at |
|---|---|
| `signer_ready: false`, "not readable" | step 3: path and `chmod`, `.env` line |
| `signer_ready: false`, "openssl" | step 2: web PHP vs CLI PHP |
| every grant refused on the board | step 6: `grant_pubkey.php` vs `license_key.h` — a different key |
| `401` from the API | `PROVISION_KEY` in `.env` must equal Orca's built-in key; `api/.htaccess` passes the header through, confirmed on this host |
| admin page warns about the view | step 4: `CREATE VIEW` privilege |
| `503 NOT_CONFIGURED` | step 7: `PROVISION_KEY` missing from `.env` |
