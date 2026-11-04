# Local OpenEMR dummy data

[seed_dummy_data.php](./seed_dummy_data.php) is a standalone CLI script for this OpenEMR checkout. It uses PDO prepared statements and the selected site's existing database connection settings. No credentials need to be copied into the script.

## What it creates

Default batch (`--patients=20 --batch=demo1`):

| Table | Rows | Purpose |
| --- | ---: | --- |
| `facility` | 2 | Active synthetic service/billing locations; neither becomes the primary business entity |
| `users` | 4 | Three calendar providers and one administrative-staff record |
| `patient_data` | 20 | Synthetic demographics, assigned providers, and matching `pid` / `pubpid` values |
| `openemr_postcalendar_events` | 40 | One historical and one current/upcoming appointment per patient |
| `form_encounter` | 20 | Historical ambulatory encounters with the correct patients, providers, and facilities |
| `forms` | 20 | `newpatient` form links needed for encounter navigation; `form_id` references `form_encounter.id`, while `encounter` references its encounter number |
| `uuid_registry` | 86 | Identity metadata for the newly created UUID-bearing records |
| `sequences` | Existing counter advances by 20 | OpenEMR's shared encounter-number allocator; follows ADODB's `GenID("sequences")` SQL |

`uuid_registry` is identity metadata, distinct from the core form-registration table `registry`. The script never writes `registry`, `globals`, `list_options`, calendar categories, authentication tables, or ACL tables. Existing demographic/transactional rows are not updated. The sole existing data update is the encounter sequence counter, so future application-created encounters cannot reuse seeded numbers.

Patient `id` and `pid` are separate identifiers. Related records consistently use `pid`; `pubpid` is a display identifier and is set to the same value for these fixtures. Other installations can legitimately use different `pubpid` values.

Patient and provider names and demographic patterns look natural for realistic demo searches, but are fictional. Patient and staff emails use `example.invalid`, patient addresses include `Demo Lane`, phone numbers use fictional 555 numbers, and email/SMS reminders are disabled. Facility names retain a `DUMMY-<batch>` marker. No real identifiers, insurance numbers, SSNs, or prescribing credentials are fabricated. Search for patients by their generated email or PID.

## Run from the VS Code PowerShell terminal

1. Export a full backup of your local `openemr` database using phpMyAdmin **Export → Custom → SQL → all tables**, saving it outside the web root. For another site/database, export that database instead. Use a disposable development instance with no real patient data.
2. Stop background jobs and pause UI activity that creates or modifies records. Keep MySQL running. A script-level lock prevents two seed processes, but does **not** block ordinary OpenEMR writers; patient IDs follow OpenEMR's `MAX(pid) + 1` convention.
3. In the project-root terminal, run the default read-only preflight:

```powershell
& 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_dummy_data.php
```

4. Review the site, database, batch, and counts printed by the script. To insert the batch:

```powershell
& 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_dummy_data.php --commit --confirm-local --no-other-writers
```

For a smaller batch, or a reproducible calendar date, use the **same options** in both preflight and commit:

```powershell
& 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_dummy_data.php --site=default --patients=8 --batch=uitest --date=2026-10-28
& 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_dummy_data.php --site=default --patients=8 --batch=uitest --date=2026-10-28 --commit --confirm-local --no-other-writers
```

This checkout's natural-looking synthetic patient batch was preflighted with:

```powershell
& 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_dummy_data.php --patients=8 --batch=demo2026 --date=2026-10-30
```

After exporting a backup and pausing OpenEMR/background writers, you can commit that exact batch by appending `--commit --confirm-local --no-other-writers` to the same command. Do not reuse `demo2026` if it has already been committed.

The default date is the PHP CLI's current date. Current/upcoming appointments start at 09:00 on that date, then spread across additional days as the batch grows. Historical appointments and encounters are about two weeks earlier. Each provider has distinct 30-minute slots. The script uses the existing active `office_visit` category.

## Safeguards and limits

- CLI only: HTTP requests return 404 before loading credentials or accessing the database.
- Default mode performs schema/required-column checks and builds an in-memory insertion plan; it performs no data writes. It does not prove that every database insert will succeed.
- Commit requires both explicit flags, a loopback database host, transactional InnoDB tables, and no triggers on written tables. A loopback address alone cannot establish that a database is disposable: check the target yourself.
- All inserts and sequence updates run in one transaction. Relationship checks run before commit; exceptions roll the transaction back. Auto-increment IDs may have gaps after failed insert attempts, as with ordinary MySQL transactions.
- Unknown options, invalid dates/counts, missing columns, missing categories, inconsistent sequences, and repeat batch markers cause failure. No `INSERT IGNORE`, foreign-key disabling, schema changes, or silent defaulting of required custom fields is used.
- Reusing a committed batch name is rejected rather than duplicating or overwriting records. A new batch name deliberately creates additional data.
- These `users` rows are provider/staff fixtures, **not login accounts**. `authorized=1` marks a provider; it does not grant administrator permissions. Sign in with your existing administrator account. If you need new login identities or permission-role tests, create them through OpenEMR's user administration UI so password and ACL setup is handled correctly.
- PDO fixture insertion deliberately bypasses service hooks, audit logging, and module-specific creation events. These records support core demographic/calendar/encounter navigation, not every module's workflow.

After committing, search for `DUMMY-demo1` in patient search, select a dummy provider in the calendar, and open a patient's historical encounter. Prescription, insurance, billing, lab, document, and custom-module records are outside this script's scope. Dummy data cannot establish that the whole system is bug-free, and it does not fix SSL/OAuth environment errors.

To reset, restore the pre-seed backup into your disposable instance. Avoid deleting only the five core tables: after UI testing, additional records may reference their patients and encounters. There is deliberately no broad automatic deletion command.

## Validation performed during authoring

PHP syntax validation and the read-only preflight passed against the installed default-site schema. No seed data was committed to your database during authoring. End-to-end UI testing requires running the commit command first.
