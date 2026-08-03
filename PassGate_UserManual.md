# PassGate — Official System Manual (Markdown copy)

> Source: `PassGate_UserManual.docx` (also in this folder and in OneDrive Documents).  
> PassGate is the official access and inventory portal for **TIIKII-enabled events**.

---

## 1. Introduction

PassGate provides a unified, secure way to:

- Track ticket custody  
- Manage distributor inventory  
- Process benefit consumption at event stations  

---

## 2. Guide for: Distributor Admin (The Event Manager)

You design the event’s digital structure and oversee the supply chain.

### System initialization

1. Open the **setup** portal  
2. Enter event parameters: ticket tiers and benefit allowances  
3. The system creates the **Vault Pool** — the central repository where all tickets start  

### Managing distributors

- Use the **Directory** (Distributors tab) to register partner companies by official email  
- Registration gives them secure access to their own inventory portal  

### Inventory allocation

- Use **Serial Allocation** to transfer ticket custody  
- Select ticket ranges (e.g. #100–#200) and assign them to a distributor  
- Those tickets leave the Vault Pool  

### System monitoring

- Use **Admin Analytics** for performance and inventory movement  
- Review **Benefit Scan Logs** to audit usage and confirm scans happen at the correct terminals  

---

## 3. Guide for: Distributors (The Asset Handlers)

You manage inventory entrusted to your company and prepare staff for the event.

### Secure access

1. Log in with your registered company email  
2. You receive a one-time login link / OTP via email (locally: written to `dev_login.log`)  
3. Open the link / enter the token to proceed  

### Dashboard & oversight

- You only see inventory **assigned to your company**  
- You cannot change event settings or see other distributors’ data  

### Inventory management

- Monitor assigned tickets  
- Keep digital custody matched with physical records  

### Benefit tracking

- If your company runs benefit stations, train on-site staff to scan accurately so consumption updates in real time  

---

## 4. Guide for: Station Users (The Gatekeepers)

You process ticket-holder entries and benefit redemptions on site.

### The scanning interface

When a guest presents a ticket, use the **Authentication Terminal**:

- Scan the QR/barcode, **or**  
- Use **manual entry** if the ticket is damaged  

### Interpreting results

| Result | Meaning | Action |
|--------|---------|--------|
| **Green** | Access granted | Fulfill the benefit |
| **Yellow** | Benefit limit reached / already claimed | Send guest to Admin for verification |
| **Red** | Access denied | Ticket invalid or not authorized for this station |

### Operational integrity

- The system syncs live — once a benefit is marked used, it updates everywhere  
- Keep the terminal powered and online  
- On failure, report immediately to your Distributor representative  

---

## Quick reference: who can do what

| Feature | Distributor Admin | Distributor | Station User |
|---------|:-----------------:|:-----------:|:------------:|
| Configure event | Yes | No | No |
| Allocate tickets | Yes | No | No |
| View inventory | Yes (all) | Yes (own only) | No |
| Scan / redeem benefits | — | — | Yes |

---

## Security & operational rules

1. **Always log out** when leaving a terminal  
2. **Email matching** — enter emails exactly as registered  
3. **Scans are permanent** — no undo for a completed benefit redemption  

---

## How this maps to the local app (Puja’s machine)

| Manual term | In the app |
|-------------|------------|
| Setup portal | http://localhost:8000/setup.php |
| Vault Pool | Unallocated tickets (Allocation tab) |
| Directory | Admin → **Distributors** tab |
| Serial Allocation | Admin → **Allocation** tab |
| Admin Analytics / scan logs | Admin → **Dashboard**, **Ledger**, **Analytics** |
| Authentication Terminal | http://localhost:8000/ (Stall / PIN login) |
| Distributor login (OTP/email) | Admin tab → magic link → `dev_login.log` locally |
| Station User | Stall login (or PIN demo) |

See also: `PROJECT_GUIDE.md` for local setup, database, and “what to do next.”
