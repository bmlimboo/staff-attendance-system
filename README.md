# CNR Staff Attendance System

Online staff attendance for the **College of Natural Resources, Royal University of Bhutan**.
Plain PHP 8 + MySQL/MariaDB, no framework, no external CDN (works fully inside the college network).

## How it works

1. A staff member signs in **from the college network** using a **registered, admin-approved laptop or phone**.
2. The system records the date and time automatically as their check-in, and marks them late if they arrive after the official start time.
3. They press **Check out** when leaving. Lunch (12:00–1:00) is not counted in hours worked.
4. Leadership roles see late-comers and reports for the scope their role allows.

| Staff type | Hours | Lunch |
|---|---|---|
| Teaching | 8:00 am – 5:30 pm | 12:00 – 1:00 pm |
| Non-teaching | 8:30 am – 4:30 pm | 12:00 – 1:00 pm |

All times, the grace period, working days and office networks are editable in **Settings**.

## Roles

| Role | What they can do |
|---|---|
| System Administrator | Everything: add / edit / delete attendance, approve devices, manage staff, departments, holidays, settings; sees all reports |
| Administrative Officer | View all attendance details and reports |
| Administrative Assistant | View all attendance details and reports |
| President | View all attendance details and reports |
| DAA | See who is late or absent in **all departments** |
| Head of Department | See who is late or absent in **their own department** (plus any extra departments assigned) |
| Section Head | Same as Head of Department, for their section / department |
| Dean | Same, for their own department plus any extra departments assigned (e.g. a Dean over DRIL, Farm and Laboratory) |
| Staff | See own attendance, register own devices |

Permissions live in one place — the `PERMS` map at the top of `app/helpers.php` — so changing who can see what is a one-line edit.

## Features

- Automatic check-in on sign-in (office network + approved device required)
- Device registration with administrator approval, limit of devices per staff, revoke / re-approve / remove
- Every person has a **designation** and a department; the staff list is grouped by department
- Heads, section heads and deans can be given extra departments to look after ("Also looks after")
- **Late & Absent** page (grouped by department, with designation, CSV export) limited to the departments a person heads
- Dashboard showing who is late today, not-yet-checked-in list and a late-arrivals trend
- Reports: per-staff summary and day-by-day detail, filter by date / department / staff type / person, **CSV export**
- Admin can add, edit and delete any attendance record (with remarks); all changes are audit-logged
- College calendar: add holidays or ranges, **import `.ics` / `.csv`**, export CSV. No one is marked late/absent on holidays or weekends
- Staff CSV import, forced password change on first sign-in, sign-in throttling, CSRF protection, audit log
- Responsive design for phones and laptops

## Project layout

```
public/      web root (Apache DocumentRoot points here)
  admin/     system-administrator pages
  assets/    CSS and JS
app/         configuration, helpers, authentication (not web-accessible)
database/    schema.sql
bin/         create_admin.php (first administrator)
docs/        DEPLOYMENT.md  - VM, local IP, subdomain, HTTPS, backups
```

## Upgrading an existing installation

After `git pull`, run the database upgrade once (safe to repeat):

```bash
sudo mysql < database/migrations/002_designation_and_dept_heads.sql
```

## Quick start

Full steps, including the local IP and subdomain setup, are in **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

```bash
sudo mysql < database/schema.sql
cp app/config.sample.php app/config.php      # edit database password
php bin/create_admin.php                     # create first administrator
# point the web server's document root at public/
```

## Design notes and limits (please read)

- **Office-network check** uses the visitor's IP address against the networks listed in Settings. It is only reliable when the server sees the real client IP (no NAT between staff devices and the server).
- **Device recognition** uses a random token stored in the browser (a cookie) that the administrator approves. A web page **cannot** reliably tell a laptop from an office desktop, so approval by the administrator is the real control. Clearing browser data, a private window, or switching browsers needs a new approval. A browser registered to one staff member cannot be registered to another.
- Staff-role accounts can only sign in from the office network. Leadership and administrator roles can sign in from anywhere to view reports, but attendance is only ever recorded from the office network on an approved device.
- "No record" days are calculated from working days (weekends and holidays excluded). Mark approved leave through **Edit Attendance** so it counts as leave, not absence.
