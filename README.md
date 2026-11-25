# HRMS (Human Resource Management System)

**Live demo: <http://horizonhrms.infinityfreeapp.com/>** (hosted on InfinityFree. Ask the admin for a login.)

People management system built from the Figma file "HRMS · HR management – Overview".
Requires PHP 7.2 or newer with PDO MySQL and mbstring, plus a MySQL-compatible database. Set a nonempty `SETUP_KEY` in `api/config.php` before making a fresh installation publicly reachable.

## Run it

1. Copy this folder to `UniServerZ\www\HRMS` and open `UniServerZ\UniController.exe`. Start **Apache** and **MySQL**.
2. Create the database and an app user (in phpMyAdmin → SQL, or the MySQL console), choosing your own password:
   ```sql
   CREATE DATABASE horizon_hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'hrms_app'@'localhost' IDENTIFIED BY 'your-password';
   CREATE USER 'hrms_app'@'127.0.0.1' IDENTIFIED BY 'your-password';
   GRANT ALL PRIVILEGES ON horizon_hrms.* TO 'hrms_app'@'localhost', 'hrms_app'@'127.0.0.1';
   ```
3. Copy `api/config.example.php` to `api/config.php` and put that password in `DB_PASS`.
4. Visit <http://localhost/HRMS/> (not the file itself — PHP must serve it). The first visit creates the tables, optionally loads sample data, and asks you to create the administrator account.

### Set up a fresh GitHub checkout on XAMPP

1. Put the checkout in `C:\xampp\htdocs\PSA-PEPS-main` and start **Apache** and **MySQL** in the XAMPP Control Panel. XAMPP 8.2.12 (64-bit) matches the tested local setup. PHP 7.2.0 also works with this project.
2. Open phpMyAdmin at `http://localhost/phpmyadmin/` and create an empty database named `horizon_hrms` if it does not exist. Use port `8080` in the browser URL if Apache is configured for it.
3. When opened from localhost without an `api/config.php` file, HRMS uses MySQL user `root` with a blank password on port `3306`. If your MySQL credentials or port differ, copy `api/config.example.php` to `api/config.php` and update the connection settings.
4. Open `http://localhost/PSA-PEPS-main/index.php` (or use port `8080` if configured). Click **Install** to create the tables, choose whether to load sample data, and create your administrator account.

The installer uses the tracked schema at `api/lib/schema.sql`. The tracked `horizon_hrms.sql` is a database dump for restoring an existing snapshot. For a fresh installation, start with an empty database and let HRMS create the tables. Each machine uses its own database credentials. Keep local passwords out of Git commits.

The PHP interface renders pages and processes forms on the server, including sign-in and CSV exports. Apache serves it by default when directory overrides are enabled.

To change a screen, edit its matching PHP template in `views/pages/` and refresh the browser. The sidebar, header, and sign-in markup are in `views/layouts/site.php`. Shared forms are in `views/partials/`. Styles are in `assets/css/app.css` and `assets/css/php.css`.

Philippine defaults: pesos (₱), Asia/Manila time, phone numbers saved as `+63 917 123 4567`.

## Modules

| Page | What it does |
| --- | --- |
| Overview | KPIs, weekly attendance chart, monthly calendar with event previews/details, pending time off, hiring summary |
| Employees | Search/filter, add, edit, offboard, delete, CSV import/export, profile with leave balance and history, sign-in access for the employee |
| Attendance | Per-day status (office / remote / away), clock-in/out times, bulk fill, CSV import/export |
| Time off | Requests with approve/decline/cancel, weekday counting, balance and overlap checks; Special and Travel Order review |
| Payroll | Monthly pay runs, bonuses, tax and benefits, approve and lock, payslips, CSV export |
| Recruitment | Roles, candidate pipeline (Applied → Hired), convert a hire into an employee |
| Reports | Headcount, turnover, attendance rate, leave by type, payroll cost, salary by department |
| Supervisor workspace | A manager's team today, leave and Special/Travel Order approvals, and who's out |
| Employee workspace | Self-service: clock in/out, request time off, Special/Travel Orders, view payslips |
| Settings | Company, currency, tax, benefits, leave allowances, profile photo, account password, theme, demo reset |

## Biometrics and the Daily Time Record (DTR)

Time in and out comes from the office biometric device:

1. On each employee's record, set **Biometric ID** to their user number on the device.
2. Export the attendance log from the device or its software (the `attlog` .dat/.txt file, or a CSV/text export). Each line needs the user number and the date and time of the punch; `YYYY-MM-DD HH:MM[:SS]` and `MM/DD/YYYY h:mm AM/PM` are both read.
3. **Attendance → Import biometrics** and upload the file. Punches already imported are skipped, so overlapping exports are safe. Unknown user numbers are listed so HR can add them to the right employee and import again.

The **DTR** (`/dtr`) lays the punches out like the printed time card: Morning IN/OUT, Afternoon IN/OUT, Overtime IN/OUT and Daily Total, for days 1–15, 16–end, or the whole month (front and back of the card side by side), with the certification line and signatures. Punches are placed in order: the first morning punch is Morning IN, the lunch punches are Morning OUT and Afternoon IN, the next is Afternoon OUT, and any later ones are Overtime. Scans within 2 minutes count once; an in/out-only day has the lunch hour deducted. Hours are filled in; Rate, Amount and Deductions are left for payroll, as on the paper card.

* Employees: **My workspace → Daily Time Record → View DTR** (their own only), then **Print**.
* HR: an employee's profile, or **Attendance → Print DTRs** for everyone (one sheet per pair of cards).

Web clock-ins also count as punches. Days without punches fall back to the attendance record's clock-in/out.

## Accounts and access

There are two kinds of sign-in:

* **HR administrator** — the account created at setup. Sees and manages everything.
* **Employee** — created by HR from an employee's profile (**Employees → open a person → Sign-in access → Give sign-in access**) with a username and a temporary password. The employee is asked to change it after signing in. Employees see only:
  * **My workspace** — clock in/out, their leave balance and requests, Special/Travel Orders, their own payslips.
  * **My team** — only if they supervise someone (someone's "Reports to" is them): their direct reports' attendance today, and approve/decline their leave and Special/Travel Order requests.
  * **Settings** (profile photo and password only) and **Help**.

The rules are enforced on the server (`authorize_action()` in `api/lib/dispatch.php` and `scope_state_to_employee()` in `api/lib/state.php`), not only hidden in the menus: employee accounts never receive other people's salaries, payslips, recruitment data or payroll settings, and any form an employee submits is forced onto their own record. Offboarding or deleting an employee stops their sign-in immediately. **Reset demo data** removes employee accounts and keeps administrator accounts.

## Structure

```
HRMS/
  index.php             server-rendered app and form controller
  views/pages/          one PHP template per screen
  views/layouts/        shared site layout
  views/partials/       reusable forms and icons
  assets/css/app.css    design tokens and styles (light + dark)
  assets/js/            small enhancements only (location picker, live search, dialogs); every page works as plain PHP forms
  api/index.php         profile photos (and a JSON API)
  api/config.php        database connection (git-ignored; copy from config.example.php)
  api/lib/              schema.sql, seed, state builder, actions (not web-accessible)
```

## Database

* Database `horizon_hrms`, user `hrms_app` (access to this database only). Credentials are in `api/config.php`.
* Tables: users, settings, employees, leave_requests, employee_requests, attendance, events, positions, candidates, pay_runs, pay_bonuses, payslips.
* Back up with phpMyAdmin (<http://localhost/us_opt1/>) or UniServer's `db_backup_restore` folder.
* **Settings → Reset demo data** replaces every record with sample data (the login is kept).
