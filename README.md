# ERP System

A complete, self-contained ERP web application built in plain PHP + MySQL —
designed to run on ordinary shared hosting (including **Hostinger's File
Manager**), with no build step, no Composer, no Node.js, and no SSH access
required.

## Updating an existing deployment

If you already deployed this app and are pulling in a newer version, check
`database/migrations/` for any `.sql` files you haven't run yet against your
live database (via phpMyAdmin) before re-uploading the application files —
they're numbered in order and safe to re-run (idempotent). Fresh installs
don't need them; `database/schema.sql` already includes everything.

## Navigation

The app is organized as 8 modules. The main sidebar (and the dashboard's
launcher cards) list only the modules; clicking one takes you into that
module, where the sidebar switches to show just that module's own
features/doctypes plus a "‹ All Modules" link back to the top level.

- **Inventory** (`inventory/`) — products, categories, inventory valuation.
- **Supply Chain** (`supply-chain/`) — warehouses (create a hierarchy of
  group and leaf warehouses, e.g. "All Warehouses" → "Stores" → "Stores /
  Shelf A"; only leaf warehouses hold stock), per-warehouse stock levels,
  manual stock entries/adjustments, and the full stock movement ledger
  (the audit trail of goods flowing in, out, or adjusted, per warehouse).
  Stock only ever moves via a Delivery Note, a Goods Receipt, a POS sale,
  or a manual stock entry here — never by editing an order directly.
- **Procurement** (`purchases/`) — vendors, and the purchase document
  flow: a Purchase Order (pending → ordered → received) does **not** by
  itself move stock; once ordered, you record a **Goods Receipt (GRN)**
  against it (choosing which warehouse the goods land in), and posting
  the GRN is what actually adds stock. From a posted GRN you can generate
  a **Purchase Invoice** (accounts payable) to track what's owed to the
  vendor and record payments against it.
- **Sales** (`sales/`) — customers' orders and the sales document flow: a
  Sales Order (pending → confirmed → shipped → completed) does **not**
  by itself move stock; once confirmed, you record a **Delivery Note**
  against it (choosing which warehouse it ships from), and posting the
  delivery note is what actually deducts stock. From a posted delivery
  note you can generate a **Sales Invoice** to bill the customer.
- **POS** (`pos/`) — a point-of-sale checkout screen: tap products to
  build a cart, charge a customer (defaults to an auto-created "Walk-in
  Customer"), and it completes the sale, deducts stock from the default
  warehouse, generates a paid invoice, and prints a receipt in one step.
- **HRMS** (`hr/`) — departments, employees, daily attendance, leave
  requests with approval, and salary slips (visible to whoever can manage
  the HRMS module — System Admin, Admin, or the HR role):
  generate a payslip per employee per month with itemized earnings and
  deductions, mark it paid (which also records the net pay as a
  "Payroll" expense under Finance), and print it.
- **CRM** (`crm/`) — customers.
- **Finance** (`accounting/`) — sales invoices (standalone or generated
  from a delivery note) with payments and automatic unpaid → partially
  paid → paid status; purchase invoices (accounts payable, generated from
  a goods receipt) with the same payment tracking; expense tracking.

Each module has its own dashboard (KPIs + recent activity) as its landing
page. Reports live under `reports/` and are linked from their relevant
module's sidebar. User management, company settings, and Print Formats
(System Admin / Admin / System Viewer only — see Roles & Permissions
below) are reached from the top-right account menu rather than the
module list, since they aren't a business module.

## Print Formats

Admin users can design custom print layouts under **Print Formats**
(account menu, top right). A print format is scoped to one document type
(Sales Invoice, Purchase Invoice, Item Master, Customer, Supplier, Sales
Order, Delivery Note, Purchase Order, Goods Receipt, or Salary Slip) — a
format you create for Sales Invoice only ever shows up when printing an
invoice, never elsewhere. The editor is an HTML
template with `{{token}}` placeholders (click any token in the sidebar to
insert it) that get replaced with that record's real data at print time;
`{{items_table}}` (or `{{earnings_table}}`/`{{deductions_table}}` for
salary slips) inserts the line-items as a formatted table. Every document
that's printable (`print.php?doctype=...&id=...`, linked from its Print
button) shows a format switcher — pick any format you've created for that
doctype, or "Standard (Built-in)" — and you can set one format as the
default for its doctype. Reports don't use this system; their Print
button is a plain browser print of the report as shown.

## Roles &amp; Permissions

Auth is session-based. A user can hold several roles at once (assigned
under Administration → Users → Roles & Permissions tab); what they can do
is the union of what each role grants:

- **System Admin** — full access, including managing Users.
- **Admin** — full access except managing Users (can view the Users list,
  can't create/edit users or change roles).
- **System Viewer** — read-only access to everything, including the
  Administration section.
- **Purchase Manager / Purchase User**, **Sales Manager / Sales User**,
  **Accounts Manager / Accounts User** — view access to every module, edit
  access to just their own module (Procurement, Sales, or Finance); the
  Manager tier can also delete records and cancel orders/invoices, the
  User tier can create and edit but not delete or cancel.
- **HR**, **CRM**, **POS**, **Supply Chain**, **Item Manager** — same
  pattern for HRMS, CRM, POS, Supply Chain, and Inventory respectively,
  each with full create/edit/delete rights in their one module.

None of the module-scoped roles can see the Administration section (Users,
Settings, Print Formats) — that's reserved for System Admin, Admin, and
System Viewer.

## Deploying on Hostinger via File Manager

1. **Create the database.** In hPanel go to **Databases → MySQL Databases**,
   create a new database and a database user with full privileges on it.
   Note the database name, username, password, and host (usually
   `localhost`).

2. **Import the schema.** Open **phpMyAdmin** from hPanel, select your new
   database, go to the **Import** tab, and upload `database/schema.sql`
   from this project. This creates all tables and a default admin login.

3. **Upload the files.** In hPanel's **File Manager**, open your domain's
   web root (usually `public_html`, or a subfolder if you want the ERP at
   `yourdomain.com/erp`). Upload the entire contents of this project there
   (you can zip the folder locally, upload the zip, then use File Manager's
   "Extract" option).

4. **Configure the app.** Edit `config/config.php` (directly in File
   Manager's code editor) and fill in:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — from step 1.
   - `APP_URL` — your site's base URL, e.g. `https://yourdomain.com` (or
     `https://yourdomain.com/erp` if installed in a subfolder), no
     trailing slash.
   - `APP_SECRET` — replace with a long random string (any random 32+
     character string works).
   - Set `APP_DEBUG` to `false` once everything is working.

5. **Log in.** Visit your site's URL. Log in with:
   - Email: `admin@example.com`
   - Password: `Admin@123`

   **Change this password immediately** — go to Users (top-left menu,
   Administration section) → edit the Administrator account → set a new
   password. You can also create additional users and assign them roles
   there (see Roles & Permissions above).

6. **Set your company details.** Under Administration → Settings, set your
   company name, currency symbol, default tax rate, and address/phone/email/
   tax ID (GSTIN) — the latter appear on the built-in Invoice, Purchase
   Order, and Salary Slip print formats.

That's it — no server restart, no CLI commands, no build step. Any time you
need to change something, just edit the PHP files directly in File Manager
(or upload a replacement) and refresh the page.

## Local development

Requires PHP 8+ with `pdo_mysql`, and a MySQL/MariaDB server.

```bash
# import the schema into a local database first, then:
php -S localhost:8000
```

Point `config/config.php` at your local database credentials while
developing, and switch them back to your production credentials before
uploading to Hostinger.

## Security notes

- All database queries use prepared statements (PDO).
- All state-changing forms are protected with CSRF tokens.
- Passwords are hashed with bcrypt (`password_hash`/`password_verify`).
- `.htaccess` files block direct web access to `/config` and `/database`,
  and to `.sql`/`.env`/`.log` files anywhere in the project.
- Set `APP_DEBUG` to `false` in production so database errors aren't shown
  to visitors.
