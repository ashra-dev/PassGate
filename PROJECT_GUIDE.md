# PassGate Pro — Project Guide (for Puja & Ashra)

**Repo:** https://github.com/ashra-dev/PassGate.git  
**Local folder:** `C:\Users\Dell\Projects\PassGate`  
**Local URL:** http://localhost:8000  

**Official user manual (roles & operations):**

- Word: `PassGate_UserManual.docx` (also in OneDrive Documents)  
- Markdown copy: `PassGate_UserManual.md`  

---

## UI redesign roadmap (Soft Event Pastels)

**Direction:** bright modern event UI — crisp white, vivid blue accent, high contrast. PHP core + shared CSS.

| Phase | Status | Scope |
|-------|--------|-------|
| 0 Design system | Done | `assets/css/passgate.css`, `includes/ui.php` |
| 1 Auth + Terminal | Done | `index.php`, `login.php`, `stall_login.php`, `manual_login.php` |
| 1.5 Terminal field UX | Done | Full-screen flash, stall-first login, auto-camera, collapsed details |
| 2 Admin shell | Done | `distributors.php` |
| 3 Setup wizard | Done | `setup.php` |
| 4 Ops polish | Done | `tickets_qr.php`, `validate.php` |
| Aesthetic refresh | Done | Bright blue modern theme |
| 5 Motion + polish | Done | Empty states, light entrance motion |
| 6 Admin ops tabs | Done | Dashboard, Ledger, Distributors, Stalls, Allocation, Analytics |
| 7 Payment portal UI | Done | Buy/customer pages themed; eSewa/Stripe/dev checkout wired |

---

## What is this project?

PassGate is the **official access and inventory portal for TIIKII-enabled events** — a ticketing and benefit-scanning system.

Think of a festival or concert where:

1. The **Event Manager (Distributor Admin)** creates an event with ticket tiers and benefit allowances  
2. All tickets start in the **Vault Pool**, then get allocated to **Distributors**  
3. Tickets get **QR codes** for print / handout  
4. At the event, **Station Users (stalls)** scan QRs to redeem benefits (Entry, Lunch, etc.)  

It uses **PHP + PostgreSQL**. The UI is built into the PHP pages (Tailwind CSS) — there is no separate React/frontend app.

Currency shown in the app: **NRS**. Timezone: **Asia/Kathmandu**.

---

## Who uses it? (roles)

| Role | How they log in | What they do |
|------|-----------------|--------------|
| **Admin** (you / Ashra) | Magic link via email (`Admin` tab) | Create events, tiers, benefits, stalls, allocate tickets, view dashboard/analytics, print QR codes |
| **Distributor** | Same magic-link login | Gets tickets allocated to them; not full admin UI |
| **Stall** | Email + password (`Stall` tab) | Unlocks the scanner terminal to redeem a benefit |
| **PIN station** | Shared PIN (default `1111`) | Demo/fallback unlock for a station — prefer stall login for real use |
| **Public** | No login | `validate.php` — look up a ticket without redeeming |

Your admin account (already created locally):

- Email: `yunaadhi1@gmail.com`
- Role: `admin`

---

## Main screens

| Page | URL | Who |
|------|-----|-----|
| Landing (home) | http://localhost:8000/ | Everyone |
| Buy tickets | http://localhost:8000/buy.php | Guests |
| Customer login / tickets | http://localhost:8000/customer_login.php | Ticket holders |
| Staff scanner login | http://localhost:8000/terminal.php | Stall / Admin / PIN |
| Admin dashboard | http://localhost:8000/distributors.php | Admin only |
| Create event wizard | http://localhost:8000/setup.php | Admin only |
| Print QR codes | http://localhost:8000/tickets_qr.php | Admin only |
| Public / staff status check | http://localhost:8000/validate.php | Read-only ticket status (not a gate scan) |
| Paste login token (dev) | http://localhost:8000/manual_login.php | Admin/distributor |
| Stall login page | http://localhost:8000/stall_login.php | Stall staff |

**Flow (account required before buy):**

1. **New guest:** Create account → Buy → pay → My tickets (QR)  
2. **Returning guest:** Log in → Buy or My tickets  
3. **Staff:** Staff login → scan QR (redeems a benefit)  
4. **Check ticket status** (`validate.php`): staff-only style tool — read-only, does **not** redeem  

Buying without an account is blocked.

### Admin dashboard tabs

- **Events** — list events, set which event the terminal uses  
- **Dashboard** — totals, recent scans, charts  
- **Ledger** — every ticket + force-scan if needed  
- **Distributors** — add people who distribute tickets  
- **Stalls** — create stall logins  
- **Allocation** — assign vault tickets to distributors  
- **Analytics** — benefit usage per ticket  
- **QR Codes** — printable QR grid  

---

## How the data fits together

```
Event
  └── Tiers (VIP, General, …)  — price + quantity
        └── Benefits (Entry, Lunch, …)  — max uses each
  └── Tickets (QR IDs)  — can be allocated to a Distributor
        └── Scans  — each time a benefit is redeemed at a Stall/Station
```

**Important rule:** a stall’s **name must match the benefit name** it redeems  
(e.g. benefit `Lunch` → stall named `Lunch`). If names don’t match, scans fail with “Station not linked to this ticket.”

Ticket IDs look like: `E1-GLO-VIP-1` (event + tier + number).

---

## Auth / login (local dev)

### Admin magic link

1. Open http://localhost:8000/terminal.php → **More options** → **Admin**
2. Enter `yunaadhi1@gmail.com` → send login link
3. Because `APP_DEBUG=1`, emails are **not** sent
4. Open `dev_login.log` in this folder and copy the URL into the browser  
   (or use **Admin: paste login token** → `manual_login.php`)

### Stall login

Create stalls in the admin **Stalls** tab first, then log in with that email/password on the Stall tab.

### PIN (demo)

Default PIN is `1111` (see `.env` → `STATION_PIN`). Only works after an event exists with benefits (stations).

---

## What’s already set up on your machine

| Item | Status |
|------|--------|
| Project folder | `C:\Users\Dell\Projects\PassGate` |
| PHP 8.4 + pgsql | Installed |
| Composer deps | Installed (`vendor/`) |
| PostgreSQL | Running; password `root` for user `postgres` |
| Database `passgate` | Created + schema loaded |
| `.env` | Configured for local |
| Admin user | `yunaadhi1@gmail.com` |
| PHP server | `php -S 127.0.0.1:8000` (restart if it stops) |

**Not set up yet:** events, tiers, benefits, tickets, stalls, allocations. That’s normal for a fresh DB.

---

## What to do next (recommended order)

### 1. Log in as admin
Use the magic-link flow above → you should land on the admin dashboard.

### 2. Create your first small test event
Go to **Setup** / create event:

- Event name (e.g. `Test Fest 2026`)
- 1–2 tiers with **small** quantities (e.g. 10 tickets each) so testing is easy
- Benefits with clear names like `Entry`, `Lunch`

### 3. Create stalls
In **Stalls** tab, create stalls whose **names exactly match** those benefits.  
Same email can be used for multiple stalls; each stall needs a **unique password**.

### 4. Print / view QR codes
Open **QR Codes**, pick a tier, try scanning one with a phone camera later.

### 5. Allocate some tickets
**Allocation** tab → pick a distributor → assign a few tickets out of the vault pool.

### 6. Test a scan
- On another browser/phone: open the terminal → **Stall** login  
- Scan or paste a ticket ID → confirm benefit is granted  
- Scan again → should hit the max-use limit  
- Check **Dashboard** / **Ledger** / public **validate.php**

### 7. Align with Ashra
Agree on:

- Who owns production hosting / SMTP email
- Whether PIN login stays enabled in production
- Naming rules for benefits & stalls
- How you share git work (branches, PRs on GitHub)

---

## Tech stack (quick)

| Piece | Detail |
|-------|--------|
| Language | PHP ≥ 8.1 |
| Database | PostgreSQL |
| Email | PHPMailer (SMTP in prod; `dev_login.log` in local debug) |
| UI | HTML in PHP + Tailwind CDN + Font Awesome |
| QR scan | html5-qrcode (browser camera) |
| Charts | Chart.js (admin dashboard) |

### Important files

| File | Role |
|------|------|
| `index.php` | Scanner terminal + login overlay |
| `distributors.php` | Admin CRM |
| `setup.php` | Event creation wizard |
| `tickets_qr.php` | Printable QR sheets |
| `api.php` | Scan / ticket API |
| `auth.php` | Login API |
| `schema.sql` | Database structure |
| `config.php` / `db.php` | Env + DB connection |
| `includes/functions.php` | Core business logic |
| `.env` | Local secrets (not committed to git) |

### Commands you’ll reuse

```powershell
cd C:\Users\Dell\Projects\PassGate

# Start the app (if not already running)
php -S 127.0.0.1:8000

# Reinstall PHP packages (if needed)
composer install
```

Database (if you ever need to reset):

```powershell
# Careful: destroys data
$env:PGPASSWORD = "root"
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -h 127.0.0.1 -c "DROP DATABASE IF EXISTS passgate;"
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -h 127.0.0.1 -c "CREATE DATABASE passgate;"
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -h 127.0.0.1 -d passgate -f schema.sql
# Then re-insert admin:
# INSERT INTO distributors (id, name, email, role) VALUES ('ADMIN01', 'Puja', 'yunaadhi1@gmail.com', 'admin');
```

---

## Local `.env` keys (what they mean)

| Key | Meaning |
|-----|---------|
| `DB_*` | PostgreSQL connection |
| `APP_URL` | Base URL used in magic login links |
| `APP_DEBUG=1` | Write login links to `dev_login.log` instead of real email |
| `MAIL_*` | Real SMTP settings (needed when `APP_DEBUG=0`) |
| `STATION_PIN` | Shared PIN for demo station unlock |
| `STATION_PIN_ENABLED` | `1` = show PIN tab; `0` = hide |

---

## Windows / local quirks to remember

1. **Login links go to `dev_login.log`**, not your Gmail, while `APP_DEBUG=1`
2. Magic-link background mail used Linux syntax before; local Windows now writes the link synchronously in debug mode
3. PHP’s built-in server handles **one request at a time**
4. Camera scanning works best on `localhost` / HTTPS
5. Tailwind/Font Awesome need internet (CDN)
6. Do **not** run `migrate.php` unless you have legacy CSV files from an older version

---

## Simple mental model

```
Admin sets up event
        ↓
Tickets generated (Vault Pool)
        ↓
Allocated to distributors → sold / handed out (QR)
        ↓
At event: Stall scans QR → benefit redeemed (scan logged)
        ↓
Admin sees live dashboard / analytics
```

---

## If something breaks

| Problem | Try |
|---------|-----|
| Can’t open localhost:8000 | Restart: `php -S 127.0.0.1:8000` in the project folder |
| Login link does nothing | Check `dev_login.log` or use `manual_login.php` |
| “No stations configured” | Create an event with benefits first |
| Stall scan says not linked | Stall **name** must match benefit **name** |
| DB connection error | Check `.env` password (`root`) and that PostgreSQL service is running |

---

*This guide was written for onboarding. Update it with Ashra as you decide production hosting, SMTP, and real event naming conventions.*
