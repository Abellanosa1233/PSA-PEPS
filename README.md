# Horizon HRMS

**Live demo: <http://horizonhrms.infinityfreeapp.com/>** (hosted on InfinityFree; ask the admin for a login)

People management system built from the Figma file "HRMS · HR management – Overview".
Runs on UniServer Zero (Apache + PHP 8.3 + MySQL 8) locally, or any PHP 8.1+ host with MySQL.

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

Philippine defaults: pesos (₱), Asia/Manila time, phone numbers saved as `+63 917 123 4567`.

## Modules

| Page | What it does |
| --- | --- |
| Overview | KPIs, weekly attendance chart, today's schedule, pending time off, hiring summary |
| Employees | Search/filter, add, edit, offboard, delete, CSV export, profile with leave balance and history |
| Attendance | Per-day status (office / remote / away), clock-in/out times, bulk fill, CSV export |
| Time off | Requests with approve/decline/cancel, weekday counting, balance and overlap checks |
| Payroll | Monthly pay runs, bonuses, tax and benefits, approve and lock, payslips, CSV export |
| Recruitment | Roles, candidate pipeline (Applied → Hired), convert a hire into an employee |
| Reports | Headcount, turnover, attendance rate, leave by type, payroll cost, salary by department |
| Supervisor workspace | A manager's team today, approvals and who's out |
| Employee workspace | Self-service: clock in/out, request time off, view payslips |
| Settings | Company, currency, tax, benefits, leave allowances, account password, theme, demo reset |

## Structure

```
HRMS/
  index.html            app shell
  assets/css/app.css    design tokens and styles (light + dark)
  assets/js/            store (API client), UI helpers, pages/*
  api/index.php         JSON API (session login, every read/write)
  api/config.php        database connection (git-ignored; copy from config.example.php)
  api/lib/              schema.sql, seed, state builder, actions (not web-accessible)
```

## Database

* Database `horizon_hrms`, user `hrms_app` (access to this database only). Credentials are in `api/config.php`.
* Tables: users, settings, employees, leave_requests, attendance, events, positions, candidates, pay_runs, pay_bonuses, payslips.
* Back up with phpMyAdmin (<http://localhost/us_opt1/>) or UniServer's `db_backup_restore` folder.
* **Settings → Reset demo data** replaces every record with sample data (the login is kept).
