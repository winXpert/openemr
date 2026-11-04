# Clinical workflow fixtures for this OpenEMR checkout

The standalone [seed_clinical_workflows.php](./seed_clinical_workflows.php) belongs in the OpenEMR root, alongside `version.php`. It is CLI-only and defaults to a **read-only preflight**. It does not depend on, rerun, or change the earlier `demo1` batch.

## Default dataset

| Tables | New records | Relationships / UI purpose |
| --- | ---: | --- |
| `facility` | 1 | Main location for this test batch, active service/billing location |
| `users` | 2 | Active calendar practitioners with `authorized=1` |
| `users_secure`, `groups`, `gacl_aro`, `gacl_groups_aro_map` | 2 each | Matching user IDs/usernames, password hashes, practice membership, membership in the existing Physicians role |
| `patient_data` | 8 | Adult patients with varied ages and demographics; each assigned a practitioner |
| `openemr_postcalendar_events` | 16 | One past and one upcoming appointment per patient, linked to the correct practitioner and facility |
| `form_encounter`, `forms` | 8 each | Historical encounters and `newpatient` form links for patient-history/Find navigation |
| `medex_recalls` | 8 | Follow-ups 7 days overdue, due on the base date, or due 3 days later |
| `patient_reminders` | 8 | Active `past_due`, `due`, and `soon_due` reminders using the existing blood-pressure action |
| `pnotes` | 24 | Eight completed chart notes and sixteen patient-linked practitioner messages, in both directions, with New/Read statuses |
| `dated_reminders`, `dated_reminders_link` | 8 each | Due-date notifications with valid sender, patient, and recipient links |
| `uuid_registry` | 35 | UUID identity metadata for newly created resources |
| `sequences`, `gacl_aro_seq` | Advance existing counters by 8 and 2 | Allocate encounter numbers and ACL identity IDs using OpenEMR's sequence convention |

Identifiers are allocated from the installed database. Patient `id` is an auto-increment storage key, while **`pid` is the patient relationship key**. New `pubpid` values equal the new `pid` values; collisions are rejected. Encounter `id` and `encounter` are also distinct: `forms.form_id` references the former, while `forms.encounter` references the latter.

The recall board in this checkout reads `medex_recalls`, not `patient_reminders`. Both are populated. `medex_recalls.r_PRACTID=0` follows the local legacy convention used by the native recall-saving flow; its actual facility relationship is `r_facility`. Internal messages use `pnotes.user` and `pnotes.assigned_to` **usernames**, while dated-reminder links use numeric user IDs. This checkout does not require a `pt_messages` table for these flows.

## Database access and configuration preservation

All queries run through **OpenEMR's native `QueryUtils::sqlStatementThrowException()` and ADODB connection**, with bound parameters. The script loads only the database helpers and an in-memory CLI session, avoiding the full web bootstrap. PDO constants in the small result adapter describe result shapes; it does not open a PDO connection.

The explicit fixture-import path skips SQL audit logging and module service hooks, including during dry-run. This is not a production data-import mechanism. Data changes are transactional and all modified tables must be InnoDB with no custom triggers. Split authentication databases are rejected because they cannot participate in the same transaction.

`globals`, `registry`, `list_options`, clinical-rule definitions, calendar categories, ACL role definitions, and existing accounts are not modified. The script adds membership records **only for the two new users** in the existing Physicians role. It never grants administrator membership. The new facility is the main facility for these fixtures; it does not replace the installation's primary business entity.

Existing custom Physicians permissions, menu settings, MFA policies, and feature enablement continue to apply. Missing required roles/categories/list values cause an error rather than being silently created.

## Execute in the VS Code PowerShell terminal

1. Back up the **current** local database outside the web root. The backup made before the earlier `demo1` seed does not contain that batch; make a fresh backup if you want to keep it.
2. Pause other application writers, scheduled jobs, and user-administration activity. Keep MySQL running. The advisory lock prevents simultaneous seed processes but does not lock normal OpenEMR users out. Do not run on a database serving real patients.
3. In the OpenEMR-root terminal, preview:

```powershell
& 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_clinical_workflows.php
```

4. To insert, supply a temporary password through an environment variable. Both new practitioners use it initially. Choose 16–72 ASCII characters containing uppercase, lowercase, a digit, and a symbol, also meeting the site's configured minimum length. The prompt hides the password, and it is never written into this script or printed:

```powershell
$seedCredential = [pscredential]::new('seed', (Read-Host 'Temporary practitioner password' -AsSecureString))
$env:OPENEMR_SEED_PASSWORD = $seedCredential.GetNetworkCredential().Password
try {
    & 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_clinical_workflows.php --commit --confirm-local --no-other-writers
    if ($LASTEXITCODE -ne 0) { throw 'Seeding failed; review the output.' }
} finally {
    Remove-Item Env:\OPENEMR_SEED_PASSWORD -ErrorAction SilentlyContinue
    $seedCredential = $null
}
```

Default practitioner usernames:

- `dummy_clinical1_0`
- `dummy_clinical1_1`

OpenEMR's `AuthHash` generates hashes using the configured algorithm. The script checks the hashes and user/practice/role relationships before commit. No existing password is reset.

### Optional arguments

- `--patients=5` through `--patients=10` changes the batch size (default 8).
- `--batch=clinical2` creates a distinctly marked batch; repeating an existing batch is rejected.
- `--site=default` selects an installed site with a loopback database host.
- `--date=2026-10-28` makes fixture dates reproducible; otherwise the PHP CLI's current date is used.
- `--inbox-user=YOUR_EXISTING_USERNAME` additionally places one New message and one dated-reminder recipient link per patient in an existing active login's inbox. It does not change that account. Without this option, notifications are addressed only to the new practitioners.

Use the same arguments in preflight and commit. For example, preview copies to your existing inbox with:

```powershell
& 'C:/wamp64/bin/php/php8.3.28/php.exe' .\seed_clinical_workflows.php --inbox-user=YOUR_EXISTING_USERNAME
```

Replace the placeholder and add the same `--inbox-user` argument to the commit command inside the password block.

## UI checks after committing

1. **Providers:** With your existing administrator account, inspect the two new active users and their Physicians membership. Sign in as a new practitioner to exercise that role's access.
2. **Find / patient records:** Search for `DUMMY-clinical1`, a first name, or a printed PID/public ID. Open demographics and the encounter dated about two weeks before the base date.
3. **Calendar:** Select either dummy practitioner and the dummy facility. Check the historical date (base date minus 14 days) and upcoming date (base date plus 7 days). Upcoming appointments deliberately have no completed encounters yet.
4. **Patient Recall board:** Set the date range to include **base date minus 7 through base date plus 3 days**, clear unrelated provider/facility filters, then view the dummy patients. A range starting today hides the overdue rows.
5. **Patient Reminders:** View active reminders for a dummy patient or in the administrative reminders list. The rows reference the existing `act_cat_measure/act_bp` action. This seeds displayable reminder results; it does not turn on rules or establish their clinical eligibility. Re-running the clinical-rule engine may replace/inactivate synthetic results.
6. **Message Center:** Sign in as either dummy practitioner, or use the optional existing inbox recipient. Check New and Read messages and the dated-reminder list. Show inactive/Done notes to see completed chart notes. Messages are linked to patient records, not to a specific encounter.

These are internal staff messages. The script does not send external Direct messages, email, SMS, or create portal conversations. Synthetic addresses use `example.invalid`; contact-consent/reminder flags disable email/SMS/voice use for these patients. Existing SSL/OAuth issues and disabled modules are separate from fixture data.

## Failure, reset, and validation

All inserts, credential/membership writes, and sequence increments share one transaction. Invalid relationships roll back. Failed transactions may leave gaps in auto-increment counters. Restore the fresh pre-seed backup to reset a disposable instance; do not delete only patient rows after testing because other UI-created records may reference them.

Validation performed: PHP syntax, live-schema read-only preflight, invalid-argument safeguards, and full insertion/credential/relationship checks against session-local temporary tables using the installed schema. The default 8-patient case and the 5-patient existing-inbox-recipient case passed. Checksums of 21 permanent tables, including `globals`, `registry`, and role definitions, were unchanged. No `clinical1` batch was committed to the application database during authoring. Browser login and end-to-end UI behavior still need checking after an actual commit.
