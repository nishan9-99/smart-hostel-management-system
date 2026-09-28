# Smart Hostel Management System

A PHP and MySQL hostel-management app built as a Database Management Systems project. The app separates administrator and student workflows, with room allocation, dues, complaints, visits, announcements and reports. This repository contains the v4.0 local-demo source, database schema and Docker setup.

> **Status:** This version was packaged for local use. A full Docker/runtime acceptance test of v4.0 has not yet been verified here. Do not treat this as a production-ready deployment. Screenshots from a running v4.0 instance are not available yet; they will be added after a verified local run.

## What it does

| Area | Administrator | Student |
| --- | --- | --- |
| Accounts | Search users; activate/deactivate accounts; one-time temporary password reset; audit trail | Register, verify email, sign in, edit profile, reset password by OTP |
| Rooms | Add rooms, set maintenance, allocate/vacate with transactions and occupancy triggers | See assigned room and roommates |
| Fees | Assign dues, inspect uploaded proof, approve/reject, export payments | See dues, submit image proof, download approved-payment PDF receipt |
| Complaints | Reply, set priority/status and manage complaints | Raise, track and reopen complaints |
| Visitors | Record entry and exit; flag visits over 12 hours | See own visitor records |
| Notifications | Broadcast or target a student | Mark visible announcements read/unread |
| Reports | Occupancy and dues views, charts, CSV export | Personal dashboard |

The schema includes foreign keys, indexes, views (`student_dues_view`, `room_occupancy_view`), a fee-assignment stored procedure, room-occupancy/payment triggers, and hourly events for overdue fees and long visits. Source: [`database/smart_hostel.sql`](database/smart_hostel.sql).

## Tech stack

- PHP 8.2 with Apache, MySQL 8.0, HTML/CSS/JavaScript
- Docker Compose for local app, database and phpMyAdmin
- PHPMailer 6.12.0 vendored with its license for optional SMTP delivery
- Optional Google OAuth for student sign-in; optional Gmail SMTP for real OTP/payment email

## Quick start (fresh local database)

1. Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) and start Docker.
2. Clone this repository, then run from its root:

   ```bash
   git clone https://github.com/nishan9-99/smart-hostel-management-system.git
   cd smart-hostel-management-system
   docker compose up --build
   ```
3. Open **http://localhost:8080** for the app. phpMyAdmin is at **http://localhost:8081**. The host MySQL port is **3308**. The first boot loads `database/smart_hostel.sql` and disposable sample data. Stop with `docker compose down` (preserves the database volume).
4. Sign in with the **local demo-only** account below, or register a student at `/auth/register.php`.

| Local demo role | Username | Password |
| --- | --- | --- |
| Admin | `admin` | `Admin@123` |
| Student | `demo01` through `demo18` | `Admin@123` |

These are public seed credentials in `database/smart_hostel.sql`, **not private credentials**. Change/remove them and the sample data before any network exposure. The Compose file also has local-only `root`/`root` MySQL credentials and opens phpMyAdmin: replace those, restrict ports and harden the stack before deployment. Without SMTP configured, verification/reset codes are shown on screen in development mode. Never expose that mode publicly.

## Existing database: upgrade without losing data

The SQL initializer runs only for a **new** MySQL volume. Back up your existing database before migrating. Never use `docker compose down -v` on a database you need: `-v` deletes the volume.

For an existing v3.2 volume, start the new app and run:

```bash
docker compose up -d --build
docker compose exec -T db mysql -uroot -proot smart_hostel < database/migrate_v4.sql
```

For older volumes, apply migrations in order as applicable: `database/migrate_v3.sql` (check for `google_id` and `auth_provider` first; a duplicate-column run fails), then `database/migrate_v3_2.sql`, then `database/migrate_v4.sql`. Fresh installs need no manual migrations. See [`README.txt`](README.txt) for detailed upgrade notes and the feature/file map.

## Optional services

**Email:** Set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_SECURE` for the web service in `docker-compose.yml`. For Gmail, use a Google App Password, not the normal account password. Do not commit a configured Compose file or real secrets. With SMTP unset, the local demo displays OTPs on screen. Payment approval/rejection emails also need SMTP.

**Google sign-in:** Create a Google OAuth web client and add `http://localhost:8080/auth/google_callback.php` as the authorized redirect URI. Put `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` in a private `.env` file next to `docker-compose.yml`. `.env` is gitignored. The Google button stays hidden without the ID and secret. Only student accounts are eligible; this integration needs your own cloud setup and has not been live-verified with credentials in this version.

**Troubleshooting:** If port 8080/8081/3308 is in use, change the left-hand host port mapping in `docker-compose.yml`. If your existing volume lacks a column/table, apply the migrations in order rather than resetting it. View container logs with `docker compose logs -f web db`. The local database user/password in Compose are for demos only.

## Project map

```text
admin/              Admin dashboard, rooms, allocation, dues, visits, audit, reports
student/            Student dashboard, room, dues, complaints, visits, notices
auth/               Login, registration, verification, reset and optional Google OAuth
includes/           Shared config, layout, audit and pagination
assets/             CSS and browser JavaScript
database/           Fresh schema/seed plus incremental migrations
reports/            CSV export endpoints
uploads/            Payment-proof upload directory with Apache execution guard
lib/PHPMailer/      Vendored mailer source and license
Dockerfile          PHP 8.2 + Apache image
docker-compose.yml  App + MySQL 8 + phpMyAdmin for local demos
README.txt          Detailed original v4 documentation
```

## Security and scope

The app uses hashed passwords and OTPs, CSRF tokens on state-changing forms, strict MySQLi/prepared statements, server-checked proof images, session cookie controls, login/reset throttles, CSV formula guards and admin audit records. These are implemented design choices, not a claim of a completed external security audit. The demo stack has unsafe public defaults, including seed credentials and a development OTP fallback. Use it locally only unless you have reviewed and hardened deployment settings.

**Screenshots:** Genuine captures from a running v4.0 app are pending; no generated mockups are presented as screenshots.

Built by Nishan Giri for BCS403 DBMS. No separate license is granted for this project; the vendored PHPMailer code carries its own license in `lib/PHPMailer/LICENSE`.
