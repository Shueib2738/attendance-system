# Student Attendance Management System

A PHP and MySQL application for managing college students, lecturers, courses, course enrollments, attendance, and attendance reports.

## Requirements

- Windows and XAMPP (Apache, PHP 8+, MySQL/MariaDB)
- A browser and phpMyAdmin
- Project files under `C:\xampp\htdocs\attendance_system`

## Start the application

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin/`.
3. Create/import the database using `config\includes\assets\database\attendance_system.sql`.
   - On a fresh install, the script creates the database and all tables.
   - For the existing project database, make a backup first, then import `config\includes\assets\database\upgrade.sql`. It adds enrollment, lecturer-account, status, and audit fields without dropping existing tables or rows. Existing free-text student course names are converted to legacy course records, and course/student pairs with attendance history are retained as enrollments.
4. The default local connection settings are in `config\database.php` (`localhost`, MySQL user `root`, blank password, database `attendance_system`). If your XAMPP MySQL account is different, update those values.
5. To create the **first** administrator on a fresh install, open a Command Prompt and run:

   ```text
   C:\xampp\php\php.exe C:\xampp\htdocs\attendance_system\config\create_admin_cli.php
   ```

   The command is restricted to PHP CLI; it cannot create an account from a browser. Use a unique username and a strong password of at least 12 characters. Administrator accounts must not be created by importing plain-text passwords.
6. Open `http://localhost/attendance_system/` and sign in.
7. Change your password from **Account security** after signing in. Administrators can add other administrator accounts from **User accounts**.

## Main folders

- `config\database.php` — MySQL connection.
- `config\includes\helpers.php` — session, authorization, CSRF, escaping, and audit helpers.
- `config\includes\header.php`, `sidebar.php`, and `footer.php` — shared layout.
- `config\includes\assets\style.css` and `script.js` — shared responsive design and browser interactions.
- `config\includes\assets\database\attendance_system.sql` — fresh-install schema.
- `config\includes\assets\database\upgrade.sql` — non-destructive upgrade for the original schema.

## Using the modules

- **Students:** create and edit records, search/filter, paginate, and assign active course enrollments.
- **Lecturers:** manage staff records and optionally create a linked lecturer login. A lecturer's course assignment is managed on the Courses page.
- **Courses:** create and edit courses, assign a lecturer, set a semester and status, and inspect current enrollment totals.
- **Enrollments:** add or remove a student-course relationship. An enrollment with attendance history is retained to keep reports accurate.
- **Attendance:** select an assigned active course and a past or current date, mark every enrolled active student, then save. Existing marks can be corrected; corrections require a reason and are written to the audit log.
- **Reports:** filter by daily, weekly, monthly, or custom dates; course and student; review actual attendance percentages; and export the filtered report to CSV. Percentages use recorded course sessions during the selected range. A missing student mark is not treated as attendance.
- **Account security:** change your own password after confirming the current password.
- **User accounts:** administrators can create administrator accounts and deactivate or reactivate users. Lecturer login accounts are linked from lecturer records.

## Back up and restore

Before schema changes or at regular intervals, use phpMyAdmin's **Export** tab to export the `attendance_system` database as SQL and save it outside `htdocs`. To restore, create/select the database in phpMyAdmin and use **Import** with the backup SQL file. Do not delete the live database as a routine upgrade step.

## Security and deployment notes

- Passwords are stored with PHP `password_hash()` and checked with `password_verify()`.
- Protected pages check the active account and role on the server. Mutating forms use session CSRF tokens and SQL prepared statements.
- Do not deploy the default XAMPP `root`/blank-password database configuration to a public server. Create a dedicated least-privilege database account, configure HTTPS, disable PHP error display, restrict database network access, and move secrets out of the web root before deployment.
- The project is intended for local college-project use and has not undergone independent penetration testing.

## Troubleshooting

- **Database connection error:** verify MySQL is running and confirm `config\database.php` settings.
- **Missing table/column after restoring an older database:** make a fresh backup and import `upgrade.sql`.
- **Lecturer has no courses:** add/login-link the lecturer, assign the lecturer to an active course, and enroll active students.
- **Cannot remove a record:** student, course, or enrollment attendance history is protected to preserve reporting; set the record inactive or retain its relationship.
- **CSRF/session message:** reload the form and submit again; a page opened before a session expires may have an old token.
- **CSS or logo not updating:** refresh the page with `Ctrl+F5` and verify Apache is serving `config\includes\assets`.
