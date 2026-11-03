# Bynnas Trade — End-to-End QA Checklist

Use this after Phase 10 to confirm the full platform works. Check each box only when the result matches **Expected**.

**Base URL:** `http://127.0.0.1:8000`  
**Start server:** `php artisan serve`

| Portal | URL | Login |
|--------|-----|--------|
| Public site | `/` | — |
| Admin | `/admin/login` | `admin@maksinternational.com` / `12345678` |
| Shop portal | `/portal/login` | `techzone@partner.local` or `gadgethub@partner.local` / `12345678` |
| Field | `/field/login` | `karim@field.local` or `sabrina@field.local` / `12345678` |

---

## 0. Environment

- [ ] `php artisan migrate` runs without errors
- [ ] `php artisan db:seed` (or individual phase seeders) completes
- [ ] Homepage `/` loads the public marketing site (not admin login)
- [ ] CSS loads: public site uses `/css/site.css`, admin uses `/css/admin.css`

---

## 1. Public site (Phase 10)

- [ ] **Home** `/` — brand “Bynnas Trade” is hero-level; Become a partner + How it works CTAs work
- [ ] **About** `/about` — page loads, explains three portals
- [ ] **Contact** `/contact` — submit form → success message; row appears in Admin → **Partner Leads** (contact messages)
- [ ] **Become a Partner** `/become-a-partner` — submit application → success; row appears under Partner applications
- [ ] Footer links: About, Contact, Partner, Admin, Field
- [ ] Mobile: Menu toggle opens/closes nav
- [ ] Admin → **Partner Leads** — change partner status + notes; mark contact message read/closed

---

## 2. Auth & RBAC (Phase 1)

- [ ] Admin login works; wrong password fails
- [ ] Logout returns to login
- [ ] **Users** — list/create/edit/activate works
- [ ] **Roles & Permissions** — roles visible; Super Admin has full access
- [ ] **Audit Logs** — login and key actions appear
- [ ] Non-admin cannot open `/admin/dashboard` without admin portal access

---

## 3. Shops, catalogue, shop portal (Phase 2)

- [ ] Admin **Shops** — list/create/show; status Active / On Hold visible
- [ ] **Products / Categories / Price Groups** — catalogue editable
- [ ] Shop portal login works
- [ ] Shop sees **Products** with their wholesale prices
- [ ] Shop can create an order → appears as `pending_audit` in Admin Orders
- [ ] Shop **Profile** loads

---

## 4. Salesmen, visits, field orders (Phase 3)

- [ ] Admin **Salesmen** — profiles with territory + monthly target
- [ ] Field login works
- [ ] Field dashboard shows target / shops / recent orders
- [ ] Check in to an assigned shop → visit page opens
- [ ] Submit order from visit → Admin Orders shows source Salesman, status pending audit
- [ ] Check out visit works
- [ ] Admin **Visits** list shows the visit

---

## 5. Order audit & stock reservation (Phase 4)

- [ ] Open pending order in Admin → credit + stock snapshot visible
- [ ] **Approve** → status Approved / stock reserved; fulfilment queue item appears (Phase 5)
- [ ] **Reject** (on another test order) → rejection reason saved
- [ ] Approve blocked when stock shortfall (unless you seed more stock first)
- [ ] Credit hold shop cannot approve without override (test if a shop is On Hold)

---

## 6. Warehouse, fulfilment, delivery (Phase 5)

- [ ] **Warehouses / Inventory** — stock balances and ledger load
- [ ] **Fulfilment** — start pick → complete pick → pack → dispatch
- [ ] **Deliveries** — mark delivered
- [ ] Order status ends as Delivered
- [ ] Invoice auto-created on deliver (Phase 7) — check order show / Invoices

---

## 7. Suppliers, PO, China shipments (Phase 6)

- [ ] **Suppliers** — create/update
- [ ] **Purchases** — create PO with lines
- [ ] **Shipments** — create / update costs / arrive / receive into warehouse
- [ ] After receive: inventory on hand increases; landed cost reflected on shipment

---

## 8. Invoices, payments, credit, returns (Phase 7)

- [ ] **Invoices** — list/show; balance matches order
- [ ] Order page can **Raise invoice** if missing
- [ ] **Payments** — record against invoice; pending → **Verify**
- [ ] After verify: invoice paid/partial updates; shop outstanding recalculates
- [ ] **Returns** — create → approve (credit + optional restock) / reject
- [ ] Over-limit shop goes **On Hold**; clearing payments can release hold

---

## 9. Targets, commissions, rewards (Phase 8)

- [ ] **Targets** — seed month / set amount / Recalc shows achievement %
- [ ] Verifying a payment accrues **Commission** (collection %)
- [ ] Target met → target bonus commission + reward pending (when rules met)
- [ ] Approve / mark paid commission; award / pay reward
- [ ] Field dashboard shows target + achieved

---

## 10. Analytics, reports, notifications (Phase 9)

- [ ] **Dashboard** — live KPIs (not demo-only numbers); charts render
- [ ] **Analytics** — billed vs collected chart; target bars
- [ ] **Reports** — open each pack; CSV download works
- [ ] Bell icon shows unread count; click marks read / opens link
- [ ] **Notifications** page lists alerts; Mark all read works
- [ ] New order / pending payment / return creates a notification

---

## 11. Happy-path full cycle (must pass)

Run once with a clean flow:

1. [ ] Shop or salesman places order  
2. [ ] Super Admin approves (stock reserves)  
3. [ ] Warehouse pick → pack → dispatch → deliver  
4. [ ] Invoice exists  
5. [ ] Record + verify payment  
6. [ ] Commission appears for salesman (if order attributed)  
7. [ ] Dashboard / Analytics reflect activity  
8. [ ] Audit log has the key events  

---

## 12. Negative / security checks

- [ ] Guest cannot open `/admin/orders`, `/portal/orders`, `/field/orders`
- [ ] Shop user cannot open admin routes
- [ ] Salesman cannot open another salesman’s unrelated admin areas
- [ ] CSRF: form posts without token fail (browser normal forms include `@csrf`)
- [ ] Invalid partner/contact form data shows validation errors

---

## Quick smoke (10 minutes)

If short on time, only run:

1. Public home + partner apply  
2. Admin login + dashboard  
3. Approve one pending order → deliver → invoice → verify payment  
4. Field login + check-in  
5. Shop portal login + products  
6. One report CSV + notifications bell  

---

*Generated for Bynnas Trade Phases 1–10.*
