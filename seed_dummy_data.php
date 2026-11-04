<?php

/**
 * Local OpenEMR fixtures. CLI only; defaults to a read-only dry run.
 * See SEED_DUMMY_DATA.md before using --commit. Never use on production.
 * Uses PDO with the selected site's existing credentials, without bootstrapping
 * globals.php (which can run unrelated application initialization side effects).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function seedQuery(PDO $db, string $sql, array $params = []): PDOStatement
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function seedUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return $bytes;
}

function seedConnection(string $site): PDO
{
    // Isolated scope: do not expose database credentials in output or arguments.
    require __DIR__ . '/sites/' . $site . '/sqlconf.php';
    if (($config ?? 0) != 1) {
        throw new RuntimeException('The selected site has not completed installation.');
    }
    if (!in_array(strtolower((string) $host), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Only a loopback database host is permitted.');
    }
    if (str_contains((string) $dbase, ';') || !ctype_digit((string) $port)) {
        throw new RuntimeException('Unsupported database connection settings.');
    }
    return new PDO("mysql:host=$host;port=$port;dbname=$dbase;charset=utf8mb4", $login, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$db = null;
$lock = false;
try {
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--(help|commit|confirm-local|no-other-writers|site|patients|batch|date)(?:=(.*))?$/D', $arg, $match)) {
            throw new RuntimeException('Unknown argument. Run with --help.');
        }
        $key = $match[1];
        $takesValue = in_array($key, ['site', 'patients', 'batch', 'date'], true);
        if (array_key_exists($key, $options) || $takesValue !== isset($match[2])) {
            throw new RuntimeException('Repeated option or incorrect option value. Run with --help.');
        }
        $options[$key] = $match[2] ?? true;
    }
    if (isset($options['help'])) {
        echo "Usage: php seed_dummy_data.php [--site=default] [--patients=20] [--batch=demo1]\n";
        echo "       [--date=YYYY-MM-DD] [--commit --confirm-local --no-other-writers]\n";
        echo "Default: read-only schema/fixture preflight. Commit requires a local test database\n";
        echo "backup and no concurrent application writers. See SEED_DUMMY_DATA.md.\n";
        exit(0);
    }
    $site = $options['site'] ?? 'default';
    $batch = $options['batch'] ?? 'demo1';
    $count = $options['patients'] ?? '20';
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $site) || !is_file(__DIR__ . "/sites/$site/sqlconf.php")) {
        throw new RuntimeException('Invalid or missing site.');
    }
    if (!preg_match('/^[a-z0-9]{1,12}$/D', $batch) || !ctype_digit($count) || (int) $count < 1 || (int) $count > 200) {
        throw new RuntimeException('Use a batch of 1-12 lowercase letters/digits and 1-200 patients.');
    }
    $count = (int) $count;
    $dateText = $options['date'] ?? date('Y-m-d');
    $base = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
    if (!$base || $base->format('Y-m-d') !== $dateText) {
        throw new RuntimeException('Invalid --date; expected YYYY-MM-DD.');
    }
    $commit = isset($options['commit']);
    if ($commit && (!isset($options['confirm-local']) || !isset($options['no-other-writers']))) {
        throw new RuntimeException('Commit requires --confirm-local and --no-other-writers. Read the guide first.');
    }
    $marker = 'DUMMY-' . $batch;
    $db = seedConnection($site);
    // Session-only settings: never alter persisted OpenEMR or server configuration.
    $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'oe-seed-' . substr(hash('sha256', $database), 0, 40);
    $lock = (int) seedQuery($db, 'SELECT GET_LOCK(?, 0)', [$lockName])->fetchColumn() === 1;
    if (!$lock) {
        throw new RuntimeException('Another seed process is running.');
    }
    $tables = ['facility', 'users', 'patient_data', 'openemr_postcalendar_events',
        'form_encounter', 'forms', 'uuid_registry', 'sequences'];
    $schema = [];
    foreach ($tables as $table) {
        $engine = seedQuery($db, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchColumn();
        if (strtoupper((string) $engine) !== 'INNODB') {
            throw new RuntimeException("$table must exist and use InnoDB for atomic rollback.");
        }
        // Triggers could mutate tables outside the documented allowlist.
        if ((int) seedQuery($db, 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$table])->fetchColumn()) {
            throw new RuntimeException("Refusing to write $table: custom triggers require manual review.");
        }
        $schema[$table] = array_column($db->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll(), null, 'Field');
    }
    foreach ([['users', 'username', "dummy_$batch" . '_%'], ['patient_data', 'email', "patient%.$batch@example.invalid"], ['facility', 'name', "$marker %"]] as [$table, $column, $value]) {
        if (seedQuery($db, "SELECT 1 FROM `$table` WHERE `$column` LIKE ? LIMIT 1", [$value])->fetchColumn()) {
            throw new RuntimeException("Batch $batch already exists. Refusing duplicate or partial reseeding.");
        }
    }
    $category = seedQuery($db, "SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_constant_id = 'office_visit' AND pc_active = 1")->fetchColumn();
    if ($category === false) {
        throw new RuntimeException('An active office_visit calendar category is required.');
    }
    $category = (int) $category;
    // Precompute an in-memory plan so dry-run has NO INSERT/UPDATE/DELETE statements.
    $plan = [];
    $virtualId = 0;
    $add = static function (string $table, array $row) use (&$plan, &$virtualId, $schema): int {
        foreach ($row as $column => $value) {
            if (!isset($schema[$table][$column])) {
                throw new RuntimeException("Schema mismatch: missing $table.$column.");
            }
        }
        foreach ($schema[$table] as $column => $definition) {
            if ($definition['Null'] === 'NO' && $definition['Default'] === null
                && !str_contains($definition['Extra'], 'auto_increment')
                && !str_contains($definition['Extra'], 'GENERATED')
                && !array_key_exists($column, $row)) {
                throw new RuntimeException("Schema requires a value for $table.$column; no rows were committed.");
            }
        }
        $id = ++$virtualId;
        $plan[] = ['key' => $id, 'table' => $table, 'row' => $row];
        return $id;
    };
    $ref = static fn(int $id): array => ['ref' => $id];
    $now = $base->format('Y-m-d') . ' 08:00:00';
    $facilities = [];
    $facilityNames = [];
    foreach (['Family Clinic', 'Community Clinic'] as $i => $name) {
        $facilityNames[$i] = "$marker $name";
        $facilities[$i] = $add('facility', [
            'uuid' => seedUuid(), 'name' => $facilityNames[$i], 'street' => (100 + $i) . ' Example Avenue',
            'city' => 'Springfield', 'state' => 'IL', 'postal_code' => '62701', 'country_code' => 'US',
            'phone' => '217-555-0100', 'email' => "clinic$i.$batch@example.invalid", 'pos_code' => 11,
            'service_location' => 1, 'billing_location' => 1, 'primary_business_entity' => 0,
            'color' => $i ? '#387D50' : '#376BA3', 'inactive' => 0,
        ]);
    }
    $providers = [];
    $usernames = [];
    foreach ([['Maya', 'Patel'], ['Daniel', 'Brooks'], ['Sofia', 'Chen'], ['Alex', 'Morgan']] as $i => [$first, $last]) {
        $usernames[$i] = "dummy_{$batch}_$i";
        $providers[$i] = $add('users', [
            'uuid' => seedUuid(), 'username' => $usernames[$i],
            'password' => 'NoLongerUsed', 'fname' => $first, 'lname' => $last,
            'authorized' => $i < 3 ? 1 : 0, 'active' => 1, 'calendar' => $i < 3 ? 1 : 0,
            'facility_id' => $ref($facilities[$i % 2]), 'facility' => $facilityNames[$i % 2],
            'billing_facility_id' => $ref($facilities[$i % 2]),
            'title' => $i < 3 ? 'Dr.' : '', 'specialty' => $i < 3 ? 'Family Medicine' : '',
            'info' => "$marker synthetic fixture; no login credentials or ACL grants. " . ($i < 3 ? 'Provider.' : 'Administrative staff.'),
            'email' => "staff$i.$batch@example.invalid", 'phone' => '217-555-0101',
        ]);
    }
    $maxPid = (int) $db->query('SELECT COALESCE(MAX(pid), 0) FROM patient_data')->fetchColumn();
    if ($maxPid + $count > 99999999999) {
        throw new RuntimeException('New patient IDs exceed the calendar pc_pid width.');
    }
    // Natural-looking names keep the patient list useful for demo searches. All
    // identifiers and contact details remain explicitly synthetic (.invalid / 555).
    $names = [['Amelia', 'Turner', 'Female'], ['Noah', 'Wilson', 'Male'], ['Ava', 'Shah', 'Female'],
        ['Liam', 'Garcia', 'Male'], ['Isabella', 'Reed', 'Female'], ['Ethan', 'Kim', 'Male'],
        ['Charlotte', 'Brown', 'Female'], ['Oliver', 'Singh', 'Male'], ['Mia', 'Thompson', 'Female'],
        ['Lucas', 'Martinez', 'Male'], ['Sophia', 'Nguyen', 'Female'], ['Benjamin', 'Carter', 'Male'],
        ['Priya', 'Desai', 'Female'], ['James', 'Mitchell', 'Male'], ['Zoe', 'Robinson', 'Female'],
        ['Arjun', 'Mehta', 'Male'], ['Grace', 'Walker', 'Female'], ['Henry', 'Bennett', 'Male'],
        ['Layla', 'Hassan', 'Female'], ['Samuel', 'Foster', 'Male']];
    $locations = [
        ['Chicago', 'IL', '60601'], ['Aurora', 'IL', '60505'], ['Naperville', 'IL', '60540'],
        ['Evanston', 'IL', '60201'], ['Oak Park', 'IL', '60302'], ['Joliet', 'IL', '60432'],
        ['Schaumburg', 'IL', '60193'], ['Elgin', 'IL', '60120'],
    ];
    $reasons = ['Annual wellness visit', 'Blood pressure follow-up', 'Seasonal allergy review', 'Routine health review'];
    for ($i = 0; $i < $count; $i++) {
        $pid = $maxPid + $i + 1;
        if (seedQuery($db, 'SELECT 1 FROM patient_data WHERE pubpid = ? LIMIT 1', [(string) $pid])->fetchColumn()) {
            throw new RuntimeException("Public patient ID $pid already exists; refusing to reuse it.");
        }
        [$first, $last, $sex] = $names[$i % count($names)];
        $p = $i % 3;
        $f = $p % 2;
        $add('patient_data', [
            'uuid' => seedUuid(), 'pid' => $pid, 'pubpid' => (string) $pid,
            'fname' => $first, 'lname' => $last, 'sex' => $sex,
            'DOB' => $base->modify('-' . (8 + ($i * 7) % 75) . ' years')->format('Y-m-d'),
            'street' => (100 + $i) . ' Demo Lane', 'city' => $locations[$i % count($locations)][0],
            'state' => $locations[$i % count($locations)][1], 'postal_code' => $locations[$i % count($locations)][2],
            'country_code' => 'US', 'phone_home' => sprintf('312-555-%04d', 100 + $i % 100),
            'email' => "patient$i.$batch@example.invalid", 'providerID' => $ref($providers[$p]),
            'date' => $now, 'regdate' => $base->format('Y-m-d'),
            'hipaa_allowemail' => 'NO', 'hipaa_allowsms' => 'NO',
        ]);
        // One past visit and one future appointment, staggered to avoid overlaps.
        $slot = intdiv($i, 3);
        $day = intdiv($slot, 12);
        $minutes = ($slot % 12) * 30;
        foreach ([-14 - $day, $day] as $visitIndex => $offset) {
            $start = $base->modify("$offset days")->setTime(9, 0)->modify("+$minutes minutes");
            $end = $start->modify('+30 minutes');
            $reason = $reasons[$i % count($reasons)];
            $add('openemr_postcalendar_events', [
                'uuid' => seedUuid(), 'pc_pid' => (string) $pid, 'pc_aid' => $ref($providers[$p]),
                'pc_informant' => $ref($providers[3]), 'pc_catid' => $category, 'pc_multiple' => 0,
                'pc_title' => "$marker $reason", 'pc_hometext' => 'Synthetic test appointment.',
                'pc_time' => $now, 'pc_eventDate' => $start->format('Y-m-d'),
                'pc_endDate' => $start->format('Y-m-d'), 'pc_startTime' => $start->format('H:i:s'),
                'pc_endTime' => $end->format('H:i:s'), 'pc_duration' => 1800, 'pc_recurrtype' => 0,
                'pc_recurrspec' => serialize(['event_repeat_freq' => '0', 'event_repeat_freq_type' => '0',
                    'event_repeat_on_num' => '1', 'event_repeat_on_day' => '0', 'event_repeat_on_freq' => '0']),
                'pc_eventstatus' => 1, 'pc_sharing' => 1, 'pc_apptstatus' => '-',
                'pc_facility' => $ref($facilities[$f]), 'pc_billing_location' => $ref($facilities[$f]),
                'pc_sendalertsms' => 'NO', 'pc_sendalertemail' => 'NO',
            ]);
            if ($visitIndex !== 0) {
                continue;
            }
            $encounter = ['sequence' => $i];
            $encounterRow = $add('form_encounter', [
                'uuid' => seedUuid(), 'pid' => $pid, 'encounter' => $encounter,
                'date' => $start->format('Y-m-d H:i:s'), 'date_end' => $end->format('Y-m-d H:i:s'),
                'reason' => "$marker $reason", 'facility' => $facilityNames[$f],
                'facility_id' => $ref($facilities[$f]), 'billing_facility' => $ref($facilities[$f]),
                'provider_id' => $ref($providers[$p]), 'pc_catid' => $category, 'class_code' => 'AMB', 'pos_code' => 11,
            ]);
            $add('forms', [
                'date' => $start->format('Y-m-d H:i:s'), 'encounter' => $encounter, 'pid' => $pid,
                'form_name' => 'New Patient Encounter', 'formdir' => 'newpatient',
                'form_id' => $ref($encounterRow), 'user' => $usernames[$p], 'groupname' => 'Default',
                'authorized' => 1, 'deleted' => 0, 'provider_id' => $ref($providers[$p]),
            ]);
        }
    }
    foreach ($plan as $record) {
        if (isset($record['row']['uuid'])) {
            $add('uuid_registry', ['uuid' => $record['row']['uuid'], 'table_name' => $record['table'],
                'table_id' => $record['table'] === 'openemr_postcalendar_events' ? 'pc_eid' : 'id', 'created' => $now]);
        }
    }
    $sequenceRows = $db->query('SELECT id FROM sequences')->fetchAll(PDO::FETCH_COLUMN);
    $maxEncounter = (int) $db->query('SELECT COALESCE(MAX(encounter), 0) FROM form_encounter')->fetchColumn();
    if (count($sequenceRows) !== 1 || (int) $sequenceRows[0] < $maxEncounter || (int) $sequenceRows[0] + $count > 4294967295) {
        throw new RuntimeException('Encounter sequence is inconsistent or exhausted; repair it separately before seeding.');
    }
    $counts = array_count_values(array_column($plan, 'table'));
    echo ($commit ? 'COMMIT' : 'DRY RUN') . " | site=$site | database=$database | batch=$batch | date=$dateText\n";
    foreach ($counts as $table => $total) {
        echo "  $table: $total new rows\n";
    }
    echo "  sequences: advance by $count on commit only\n";
    if (!$commit) {
        echo "Preflight passed. No data was written; SQL execution/constraints are only exercised on commit.\n";
    } else {
        $db->beginTransaction();
        $currentSequence = $db->query('SELECT id FROM sequences FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if ($currentSequence !== $sequenceRows || (int) $db->query('SELECT COALESCE(MAX(pid), 0) FROM patient_data')->fetchColumn() !== $maxPid) {
            throw new RuntimeException('Data changed during preflight. Stop other writers and rerun.');
        }
        $ids = [];
        $encounters = [];
        for ($i = 0; $i < $count; $i++) {
            // Same allocator SQL used by ADODB GenID("sequences").
            $db->exec('UPDATE sequences SET id = LAST_INSERT_ID(id + 1)');
            $encounters[$i] = (int) $db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        }
        foreach ($plan as $record) {
            $row = $record['row'];
            foreach ($row as &$value) {
                if (is_array($value)) {
                    $value = isset($value['ref']) ? $ids[$value['ref']] : $encounters[$value['sequence']];
                }
            }
            unset($value);
            $columns = '`' . implode('`,`', array_keys($row)) . '`';
            $placeholders = implode(',', array_fill(0, count($row), '?'));
            seedQuery($db, "INSERT INTO `{$record['table']}` ($columns) VALUES ($placeholders)", array_values($row));
            $ids[$record['key']] = (int) $db->lastInsertId();
        }
        // Verify patient/provider/facility/form joins before committing any rows.
        $linked = seedQuery($db, "SELECT COUNT(*) FROM form_encounter e
            JOIN patient_data p ON p.pid = e.pid AND p.pubpid = CAST(p.pid AS CHAR)
            JOIN users u ON u.id = e.provider_id
            JOIN facility f ON f.id = e.facility_id
            JOIN facility b ON b.id = e.billing_facility
            JOIN forms r ON r.form_id = e.id AND r.pid = e.pid AND r.encounter = e.encounter
                AND r.formdir = 'newpatient' AND r.deleted = 0 AND r.provider_id = u.id
            WHERE p.pid > ? AND p.pid <= ?", [$maxPid, $maxPid + $count])->fetchColumn();
        $appointments = seedQuery($db, "SELECT COUNT(*) FROM openemr_postcalendar_events a
            JOIN patient_data p ON a.pc_pid = p.pid JOIN users u ON a.pc_aid = u.id
            JOIN facility f ON a.pc_facility = f.id JOIN facility b ON a.pc_billing_location = b.id
            WHERE p.pid > ? AND p.pid <= ?", [$maxPid, $maxPid + $count])->fetchColumn();
        if ((int) $linked !== $count || (int) $appointments !== 2 * $count) {
            throw new RuntimeException('Relationship verification failed.');
        }
        $db->commit();
        echo "Committed. Patient PIDs: " . ($maxPid + 1) . '-' . ($maxPid + $count)
            . ". Patients have natural-looking fictional names; search by their generated .invalid email or PID.\n";
    }
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    // PDO connection exceptions may contain connection details; do not print them.
    $message = $error instanceof PDOException
        ? 'Database operation failed (SQLSTATE ' . $error->getCode() . '). Check schema, permissions and connectivity; no seed rows were committed.'
        : $error->getMessage();
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
} finally {
    if ($lock && $db instanceof PDO) {
        seedQuery($db, 'SELECT RELEASE_LOCK(?)', [$lockName]);
    }
}
