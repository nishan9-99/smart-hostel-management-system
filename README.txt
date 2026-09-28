====================================================
  Smart Hostel Management System
  Course : BCS403 - Database Management Systems
  Sem    : IV | Branch: CSE
====================================================

SETUP (Docker)
--------------
1. Install Docker Desktop  ->  https://www.docker.com
2. Open a terminal in the project root (smart_hostel/)
3. First-time setup:
       docker-compose up --build
4. For an existing v2.1 database volume, follow "UPGRADING AN EXISTING
   V2.1 DATABASE" below before Google sign-in. Do not run down -v: that
   deletes your database. A full DB reset is only for a disposable demo:
       docker-compose down -v
       docker-compose up --build
5. Open in a browser:
       App         ->  http://localhost:8080
       phpMyAdmin  ->  http://localhost:8081

DEFAULT LOGIN
-------------
  Admin
    Username : admin
    Email    : admin@smarthostel.com
    Password : Admin@123     <- change it after first login (Profile page)

  Student
    Register at  http://localhost:8080/auth/register.php

DATABASE
--------
  Host     : db (Docker service name) | Port: 3306 (3308 from host)
  User     : root | Password: root
  Database : smart_hostel

TABLES
------
  users             - admin + student accounts (bcrypt hashed passwords)
  password_resets   - OTP password-reset codes (hashed, expiring, single-use)
  rooms             - room inventory
  room_allocations  - which student is in which room
  visitors          - visitor entry/exit log (FK to users)
  complaints        - student complaints + admin replies
  payments          - fee dues + proofs + status (single proof_file column)
  notifications     - broadcast/targeted announcements
  notification_reads - per-student read timestamps
  admin_audit_log   - admin state-change record
  registration_verifications - hashed registration OTPs
  reset_attempts    - forgot-password throttle

DBMS CONCEPTS DEMONSTRATED (all in database/smart_hostel.sql)
-------------------------------------------------------------
  DDL / constraints : CREATE TABLE, ENUM, FK, UNIQUE, CHECK via triggers
  DML               : INSERT, UPDATE, DELETE, SELECT
  Joins             : allocations + rooms + users, visitors + users
  Aggregates        : SUM, COUNT, GROUP BY (dashboards, reports)
  Subqueries        : dashboard stat queries
  Prepared stmts    : every query (SQL-injection safe)
  Triggers          : trg_allocation_after_insert / after_delete /
                      after_update (auto rooms.occupied + status),
                      trg_payments_before_insert / before_update
                      (auto-mark overdue fees)
  Views             : student_dues_view, room_occupancy_view
                      (wired into admin/reports.php)
  Stored procedure  : assign_fee(student_id, fee_type, amount, due_date)
                      (used by admin/payments.php fee assignment)
  Event             : ev_mark_overdue_fees (hourly; needs
                      --event-scheduler=ON, set in docker-compose.yml)
  Indexes           : payments.student_id, complaints.user_id,
                      room_allocations.user_id (+ FK indexes)
  Transactions      : room allocation flow (BEGIN ... COMMIT/ROLLBACK)

SECURITY NOTES
--------------
  * Login verifies bcrypt hashes only (no plaintext fallback).
  * Forgot password = 4-digit OTP, stored HASHED in password_resets,
    expires in 10 minutes, max 5 attempts, single use. The old
    "email only" reset is gone.
  * CSRF tokens on every state-changing form (send/delete/approve/
    pay/allocate/profile/logout...). Notification delete is POST now.
  * check_username.php is rate-limited per session (30 checks / 10 min)
    to stop username enumeration.
  * Payment proof uploads: extension comes from the detected MIME
    type (never the user filename), images only, getimagesize()
    re-validates, random server-side name, and
    uploads/payment_proofs/.htaccess disables the PHP engine there.
  * mysqli runs in strict mode (MYSQLI_REPORT_ERROR | STRICT).
  * CSV exports guard against spreadsheet formula injection.
  * Logout is POST + CSRF.

EMAIL / SMTP (OTP delivery)
---------------------------
  The OTP flow always works. Without SMTP it runs in DEV MODE:
  the code is shown on the verification screen in a yellow banner
  (and written to the PHP error log) so you can demo the full flow
  with zero setup.

  To send REAL emails, the app uses PHPMailer over SMTP (installed
  from lib/PHPMailer inside the project - no Composer needed).

  Gmail setup (5 minutes):
    1. Turn on 2-Step Verification on the sending Google account:
       myaccount.google.com -> Security -> 2-Step Verification
    2. Create an App Password:
       myaccount.google.com/apppasswords -> name it "Smart Hostel"
       -> copy the 16-character password
    3. In docker-compose.yml (web service -> environment) uncomment
       and fill in:
         SMTP_HOST: smtp.gmail.com
         SMTP_PORT: 587
         SMTP_USER: youraddress@gmail.com
         SMTP_PASS: the 16-character app password
         SMTP_FROM: youraddress@gmail.com
         SMTP_SECURE: tls
    4. Rebuild:  docker compose down && docker compose up --build

  Notes:
    * Your normal Gmail password will NOT work - Google blocks it.
      It must be an App Password.
    * Keep SMTP_PASS out of GitHub - it is a real credential.
    * If delivery fails, the verify screen shows a warning banner
      (and the PHP error log has details). The code is never shown
      on screen while SMTP is configured.
    * SMTP_SECURE accepts tls (default, port 587), ssl (port 465)
      or off (plain - only for local test relays).

PASSWORD FIELD UX
-----------------
  Every password field in the app (login, register, forgot/reset,
  profile change-password) has:
    * an eye-icon show/hide toggle
    * a live strength checklist on new-password fields
      (8+ chars, uppercase, lowercase, number, special character)
      turning green/red as you type
    * a live "passwords match" indicator on confirm fields
  Implemented globally in assets/js/app.js (pwEnhanceAll) driven by
  data-pw-meter / data-pw-match attributes - no per-page scripts.

PAGE STRUCTURE (honest map)
---------------------------
  Shared layout (includes/header.php, sidebar.php, footer.php) is
  used by EVERY page under admin/ and student/.
  Auth pages (login with register panel, forgot, reset) share
  auth/_auth_layout_top.php + _auth_layout_bottom.php.
  index.php and auth/verify_otp.php are standalone styled pages.
  All pages use the shared safe() escape helper from
  includes/config.php - no page defines its own escape function.

FILE MAP
--------
  index.php                     landing page
  lib/PHPMailer/               vendored PHPMailer 6.12.0 (source + license)
  includes/config.php           DB + strict mysqli + helpers + CSRF + OTP mailer
  includes/audit.php            admin action audit writer
  includes/pagination.php       shared list pagination
                                (PHPMailer SMTP with dev-mode on-screen fallback)
  includes/header.php           shared <head> + css
  includes/sidebar.php          role-aware navigation + user card + logout form
  includes/footer.php           footer + js
  assets/css/style.css          design system (no external dependencies)
  assets/js/app.js              modals, confirms, auth blade transition
  assets/js/password-ui.js      global eye toggle, strength and match UX
  auth/login.php                sign-in (bcrypt only)
  auth/register.php             student self-registration
  auth/logout.php               POST + CSRF logout
  auth/check_username.php       rate-limited availability probe
  auth/forgot_password.php      issues hashed OTP
  auth/verify_otp.php           OTP entry UI (animated) + verification
  auth/verify_registration.php  registration email OTP confirmation
  auth/reset_password.php       password change (verified sessions only)
  admin/dashboard.php           live stats
  admin/audit_log.php           admin action audit history
  admin/users.php               user list + search + role filter
  admin/rooms.php               add/delete rooms, maintenance toggle
  admin/room_allocation.php     allocate / vacate (transaction + triggers)
  admin/visitors.php            visitor log (visit_date, FK, mark exit)
  admin/complaints.php          reply + status + delete
  admin/payments.php            assign_fee() proc, approve/reject, delete
  admin/reports.php             views-powered reports + CSV export links
  admin/notifications.php       send + POST delete
  admin/profile.php             details + password change
  student/dashboard.php         personal overview
  student/room_details.php      own room + roommates
  student/payments.php          dues + Pay Now modal
  student/clear_payment.php     hardened proof upload
  student/complaints.php        raise + track complaints
  student/visitor_records.php   own visitor history
  student/notifications.php     announcements
  student/profile.php           details + password change
  database/migrate_v4.sql       one-time idempotent v4 schema migration
  errors/404.html, 500.html     matching static error pages
  reports/_csv.php              shared CSV bootstrap + formula guard
  reports/export_users.php      CSV export (kept - the admin/ duplicate
  reports/export_payments.php   was removed; exports live only here)
  reports/export_rooms.php      CSV export (uses room_occupancy_view)
  reports/export_complaints.php CSV export
  uploads/payment_proofs/       proof images (.htaccess: no script exec)

UPGRADING AN EXISTING V2.1 DATABASE
-----------------------------------
  Docker's database initializer only runs on a new/empty volume. If you
  already have users and data from v2.1, DO NOT use docker compose down -v.
  Run the following SQL once against the smart_hostel database before
  using Continue with Google (phpMyAdmin at http://localhost:8081 ->
  smart_hostel -> SQL, or the mysql command below):

    ALTER TABLE users
      ADD COLUMN google_id VARCHAR(255) DEFAULT NULL UNIQUE,
      ADD COLUMN auth_provider ENUM('local','google') NOT NULL DEFAULT 'local';

  mysql client option, from smart_hostel/ after docker compose up -d:
    docker compose exec -T db mysql -uroot -proot smart_hostel < database/migrate_v3.sql

  The SQL is in database/migrate_v3.sql. Run it ONCE for an old volume;
  on a fresh v3/v3.1 install these columns already exist. Before running,
  check phpMyAdmin -> users -> Structure: if google_id AND auth_provider
  are already present, SKIP the step. Running this SQL twice causes a
  duplicate-column error (it will not delete data). Google sign-in assumes
  these columns exist and no longer alters the schema during a login.

V3: ONE-CARD LOGIN / GOOGLE SIGN-IN
-----------------------------------
  Open http://localhost:8080/auth/login.php. The Sign in and Create
  account forms live on ONE page/card, with a diagonal dark-green blade
  sliding between the cream form and decorative panels. The Register
  link and /auth/register.php both open the same page in register mode.
  Motion is disabled when the browser requests reduced motion. Existing
  registration fields, CSRF, username check, OTP reset and role guards
  remain in place. Checking "Keep me signed in" uses PHP's own session
  cookie for 30 days, so closing and reopening the browser keeps the login.
  Leaving it unchecked uses a browser-session cookie that expires when the
  browser closes. Logout ends the server session and clears either cookie.
  A remembered session may expire sooner if the server deletes session data.

  PHPMailer 6.12.0 is vendored under lib/PHPMailer; no Composer is needed.
  Gmail SMTP setup remains as above (App Password, NOT your normal password).
  SMTP_HOST unset keeps the on-screen dev OTP. Do not ship a real SMTP
  password in the ZIP or commit an .env file.

  Google OAuth setup:
    1. In Google Cloud Console, select or create a project. Set up the
       OAuth consent screen (External for personal Gmail users). In
       Testing mode, add each intended Google account as a test user.
    2. Create Credentials -> OAuth client ID -> Web application.
    3. Add this exact Authorized redirect URI:
       http://localhost:8080/auth/google_callback.php
       On a deployed site use its public HTTPS URL instead. Google must
       accept the exact scheme, host, port and path.
    4. Create a private .env file next to docker-compose.yml containing:
       GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
       GOOGLE_CLIENT_SECRET=your-client-secret
       GOOGLE_REDIRECT_URI=http://localhost:8080/auth/google_callback.php
       Never commit .env or paste the client secret into chat.
    5. Run docker compose up --build from smart_hostel/. Without BOTH
       client ID and secret, the Google button stays hidden. Check login
       and register panels for "Continue with Google" when configured.

  On first Google login a verified Gmail address matching an existing
  active STUDENT account is linked to that account; an unknown address
  creates a student account. Google-only users receive a random unusable
  local password, and can complete phone and address in My Profile.
  Existing admin accounts cannot sign in or be created through Google.
  Non-Gmail Google accounts cannot automatically link to an existing
  email/password account; use the local password for that account.
  The Google subject is stored in users.google_id, preventing the account
  from silently switching to another Google identity. For an existing
  v2.1 database volume, run the one-time upgrade above BEFORE using Google.
  No live Google OAuth is verified until YOUR Cloud credentials are set.

OPTIONAL, NOT IMPLEMENTED
-------------------------
  Dashboard dark mode. Login rate limiting, registration email verification,
  and admin audit logging are implemented.

V3.2: LOGIN SECURITY, ADMIN ACTIONS, PAYMENT EMAIL & RECEIPTS
-------------------------------------------------------------
  Existing v3.1 Docker volumes: run this one-time, idempotent schema upgrade
  AFTER starting the containers, BEFORE trying password login on v3.2:
    docker compose up -d --build
    docker compose exec -T db mysql -uroot -proot smart_hostel < database/migrate_v3_2.sql
  Do NOT run docker compose down -v: it deletes your database. Fresh installs
  create login_attempts automatically from database/smart_hostel.sql.

  Password login records failed attempts by submitted identifier (including
  unknown identifiers). Five failures within 15 minutes lock that identifier
  until the oldest failure leaves the 15-minute window. Success clears its
  attempts. Google OAuth does not use this limit. Session cookies are HttpOnly,
  SameSite=Lax, and Secure on HTTPS. For HTTPS behind a trusted TLS proxy set
  APP_FORCE_HTTPS=1 for the web service; do not enable it on local HTTP Docker.
  Logout clears the cookie with the same attributes.

  Admin > Users: deactivate/reactivate accounts or reset a password. A reset
  shows a random temporary password once in that response. Copy it immediately,
  share privately, and ask the user to change it after login. The temporary
  password is not emailed or logged. An admin cannot deactivate their own account.
  Admin > Payments sends approval/rejection emails via the existing SMTP setup.
  When SMTP is not configured or delivery fails, the payment action still works;
  check server logs for delivery issues. Approved students can use Download
  receipt under My Payments. The receipt is a PDF restricted to their own
  approved payment. Unicode names are transliterated to Latin text in the
  lightweight PDF; the database names are not changed.

  Smoke tests on your running environment: fail five password logins and try
  the correct password while locked, then wait for the cooldown; try Google,
  check local HTTP and HTTPS cookie attributes and POST logout; verify admin
  actions including self-deactivation guard; approve/reject a proof with SMTP
  configured and unset; check own/other/unapproved receipt URLs. Also recheck
  OTP, password UX, blade transition, OAuth linking and 30-day login persistence.

V4: FINAL RELEASE - NORMALIZED DATA AND ADMIN CONTROLS
------------------------------------------------------
  Existing v3.2 Docker volumes: back up the database, then run once (safe to
  rerun). Do NOT use docker compose down -v; that deletes the existing data.
    docker compose up -d --build
    docker compose exec -T db mysql -uroot -proot smart_hostel < database/migrate_v4.sql
  Fresh volumes use database/smart_hostel.sql automatically. Migrations on old
  volumes must be applied in sequence (v3, v3.2, then v4 as applicable).

  Complaints and payments no longer duplicate students' names; they join
  users by FK. The migration reconciles old name copies before dropping the
  columns, then recreates assign_fee to omit the old payment name column.
  A user already deleted before migration cannot have their name reconstructed
  from a null FK; exports display 'Deleted student' in that case.

  Admin audit log records user status and password-reset changes, room
  add/delete/maintenance and allocation/vacate, visitor add/exit/delete,
  admin profile/password changes, fee assignment/approval/rejection/deletion,
  complaint updates/deletion, and notification sends/deletion. Admin > Audit Log has
  admin/action/date filters and 20-row pages. Users, payments, visitors,
  complaints, and student notifications are paginated too. Filters/search
  persist in pagination URLs. Every new write checks CSRF; admin audit rows
  commit in the same transaction as the action.

  New local student registrations begin pending_verification, and must enter
  the emailed 4-digit OTP (hashed, expiring in 10 minutes, max five tries).
  Without SMTP, the code appears on the registration verify page for local
  demos; with SMTP, failed delivery shows an error and Resend is available.
  The Login page includes a Verify registration link. Login, password reset,
  fee assignment and room allocation exclude pending students. Google OAuth
  still makes its own verified-email active account as before.

  Notifications now support optional single-student targeting; default remains
  All Students. Each student can mark a visible notification read/unread, and
  the sidebar shows a red unread badge. Student dashboard shows only visible
  announcements. Visitors still Inside after 12 hours get an hourly auto-flag
  and red Long visit badge; they are never auto-exited. Students pick complaint
  Low/Normal/High priority and can reopen their own Resolved/Closed complaint;
  admins can edit priority while replying.

  Fee-assignment errors are logged but no raw SQL exception reaches the UI.
  Forgot-password requests, including unknown emails and OTP resends, are
  throttled per email and IP after five requests in 15 minutes. Two dependency-
  free dashboard bar charts show room occupancy by type and approved fee
  collections by month. Fresh installs have 18 clearly labeled disposable
  demo students (demo01..demo18, same password as the demo admin) and sample
  payments, complaints and visits. Never deploy with demo credentials.
  Apache serves matching 404/500 pages; PHP display_errors is off by default,
  logs remain on. APP_DEBUG=1 is only for a private local demo.

ER RELATIONSHIPS (V4)
---------------------
  users.id <- complaints.user_id (title, status, priority, no name copy)
  users.id <- payments.student_id (fee/status/proof, no name copy)
  users.id <- visitors.student_id
  users.id <- room_allocations.user_id -> rooms.id
  users.id <- registration_verifications.user_id (one pending OTP)
  users.id <- admin_audit_log.admin_id (nullable when admin deleted)
  users.id <- notifications.target_user_id (nullable = broadcast)
  users.id + notifications.id <- notification_reads (composite PK)

V4 FILE MAP ADDITIONS
---------------------
  database/migrate_v4.sql         idempotent upgrade for existing volumes
  admin/audit_log.php             filterable, paginated audit table
  includes/audit.php              transactional admin audit writer
  includes/pagination.php         shared 20-row page window + nav
  auth/verify_registration.php    registration OTP entry and resend
  errors/404.html, errors/500.html static styled error pages
