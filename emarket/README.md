# 🧺 E-Market Cogon

Web-based E-Market with online ordering, sales management, and stall rental reservation / records / monthly dues
for selected Cogon Market vendors (PHP + MySQL + HTML/CSS/JS, per the concept paper).

## Setup (XAMPP)
1. Copy the `emarket` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Open `http://localhost/emarket/install.php` — creates the `cogon_emarket` database + demo data.
4. Open `http://localhost/emarket/`. Delete `install.php` afterwards.

If you rename the folder or use other DB credentials, edit `config.php` (`BASE`, `DB_*`).

## Demo logins
| Role | Email | Password |
|---|---|---|
| Administrator | admin@cogon.test | admin123 |
| Market Staff / LGU | staff@cogon.test | staff123 |
| Vendor | ben@cogon.test (also rosa@, linda@) | vendor123 |
| Customer | juan@cogon.test | customer123 |

## What's implemented (mapped to the paper)
- **Customer:** guest browsing of vendors / stall locations / products; account only to order; cart → one order per vendor; pickup, cash on pickup; order tracking + notifications; cancel while pending; order history; report a vendor.
- **Vendor (mobile-friendly dashboard):** products, prices, units (kg / piece / pack), stock + low-stock status; accept / reject; preparing → ready → completed; **actual weight & final price for wet goods**; mark uncollected; sales records & 7-day chart; view own stall, lease, permit, dues, payment history; report a customer.
- **Safeguards:** vendor response timer (auto-cancel), pickup window, uncollected = strike, flagged → blocked at max strikes (admin reviews). Stock reserved on accept, returned on reject / cancel / uncollected.
- **Market Staff / LGU:** sections & stalls (vacant / reserved / occupied), applications & reservations, rental agreements (renewal / transfer keeps history), business permits (valid / due / expired), monthly billing, payments with OR numbers, overdue monitoring, rental status summary (printable).
- **Admin:** accounts (vendors are admin-created only, staff accounts), listing & order monitoring, reports & flagged buyers, system / rental / market-price summary reports, categories, safeguard settings. Admin can also do all staff functions.
- **Eligibility rule:** a vendor is listed only with an **active stall agreement AND a valid business permit**.
- Role-based access, CSRF tokens, password hashing, prepared statements.

Out of scope (per delimitations): online payment, delivery, SMS, printing legal contracts.
