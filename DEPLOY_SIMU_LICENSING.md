# Deploying Simu licensing

Copy-paste steps, in the order that keeps the site working at every point.
Companion to `DEPLOY_PROVISIONING.md` and `SERVER_GIT_COMMANDS.md`.

**What this is:** Simu (the PC software) asks this server for its licence.
`api/simu_request.php` records the PC as pending, you approve it on
`admin/simu_licenses.php`, and `api/simu_poll.php` hands the signed file back.
For a PC without internet you type its Machine ID on the same admin page and
download the file. One row per PC, kept in `simu_licenses`.

**Why the order matters:** the admin page reads two new tables and the API
reads a private key. Do the key and the tables first — neither touches
anything the current code uses — and the pull lands on a server that is
already ready for it.

---

## 0. The key

`simu_license_key.pem` is the private key every Simu licence is signed with;
`PUBLIC_KEY_PEM` in `Simu-Backend/app/licensing.py` holds its public half.
It is a **different key from `grant_key.pem`** on purpose: the firmware key
can never change, this one could (at the price of rebuilding Simu.exe and
re-approving every PC).

It already exists on the development PC at `C:/AL/Private/simu_license_key.pem`
(made 2026-09-22 with `Simu-Backend/tools/simu_license.py keygen`).

- **Back it up offline** — a USB stick in a drawer, next to grant_key.pem.
- Copy it to the server **outside `public_html`**: `/home/sgkk4203/simu_license_key.pem`.

```bash
# from the PC
scp C:/AL/Private/simu_license_key.pem sgkk4203@myco-grid.com:/home/sgkk4203/simu_license_key.pem
# on the server
chmod 600 /home/sgkk4203/simu_license_key.pem
```

---

## 1. Local: commit and push

```bash
cd C:/AL/Private/orca_backend
git status                       # 2 modified (.env.example, header.php), 8 new
git add -A
git commit -m "Simu licensing: one licence per PC, requested by Simu, approved here"
git push origin main
```

---

## 2. Server: the tables

```bash
cd /home/sgkk4203/public_html/orca_backend
mysql -u <db_user> -p orca_iot < database/migration_simu_licenses.sql
```

CREATE-only, safe to run twice. Check:

```sql
SHOW TABLES LIKE 'simu_%';        -- simu_licenses, simu_license_history
```

---

## 3. Server: `.env`

Add to `/home/sgkk4203/public_html/orca_backend/.env`:

```
SIMU_LICENSE_KEY_PATH=/home/sgkk4203/simu_license_key.pem
SIMU_APP_KEY=5b2e3a7e6b42fbed5261dedad70d3cbe06cbb63029df142441a2bbf622e5cd9b
```

`SIMU_APP_KEY` must equal `license_app_key` in `Simu-Backend/app/config.py`
— it is built into Simu.exe. It is a door, not a secret: it keeps the request
endpoint from answering the open internet; who gets a licence is decided on
the admin page.

---

## 4. Server: pull

```bash
cd /home/sgkk4203/public_html/orca_backend
git pull origin main
```

---

## 5. Check

```bash
php tools/simu_license_pubkey.php
```

Must print the key path, the PEM, and `Self-test: ... verify OK`. Compare the
PEM with `PUBLIC_KEY_PEM` in `Simu-Backend/app/licensing.py` — same block, or
every licence issued here is refused by Simu. (Copy `licensing.py` up and pass
its path as the argument for a one-line MATCH/MISMATCH.)

Then open **Admin → Devices → Simu Licences**. The status line must say
*Signing key: ready* and *App key: set*.

From a PC with the new Simu: the activation page → type a company name →
*Request licence* → the row appears under *Waiting for approval* → Approve →
the page on the PC unlocks within 30 seconds.

---

## 6. The three PCs that had the old licence

The old Ed25519 files (Almas Dev, Almas Internal, Test machine) no longer
verify. Each of those PCs shows the activation page once; request from it and
approve here. Nothing else to do.

---

## If it goes wrong

- **"Signing key not available"** on the admin page — `SIMU_LICENSE_KEY_PATH`
  wrong, or the file unreadable by the web user. `ls -l` it.
- **Simu says "App key not recognised"** — `SIMU_APP_KEY` in `.env` differs
  from `license_app_key` in `app/config.py`.
- **Simu says "The server sent a licence this PC cannot use: License signature
  is invalid"** — the server signs with a different key from the one in the
  exe. `php tools/simu_license_pubkey.php path/to/licensing.py`.
- **Request never appears** — the PC's request went somewhere else: check the
  server URL on the activation page ("via ...") against `license_server_url`.
