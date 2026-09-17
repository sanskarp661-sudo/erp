# ERP System

A complete, self-contained ERP web application built in plain PHP + MySQL —
designed to run on ordinary shared hosting (including **Hostinger's File
Manager**), with no build step, no Composer, no Node.js, and no SSH access
required.

## What's included

- **Auth & roles** — admin / manager / staff, session-based login.
- **Inventory** — products, categories, stock movements, low-stock alerts.
- **Sales / CRM** — customers, sales orders with a pending → confirmed →
  shipped → completed workflow (confirming an order deducts stock;
  cancelling a confirmed/shipped order restores it).
- **Purchases** — vendors, purchase orders with a pending → ordered →
  received workflow (receiving adds stock).
- **Accounting** — invoices (standalone or generated from a sales order),
  printable invoice view, payments with automatic unpaid → partially paid →
  paid status, and expense tracking.
- **HR** — departments, employees, daily attendance, leave requests with
  approval.
- **Reports** — sales, inventory, purchases, and a financial (revenue vs.
  expenses) report, each with charts.
- **Admin** — user management and company settings (name, currency, tax).

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
   password. You can also create additional users with Manager or Staff
   roles there.

6. **Set your company details.** Under Administration → Settings, set your
   company name, currency symbol, and default tax rate.

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
