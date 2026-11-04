<?php

/**
 * Local OpenEMR fixtures. CLI only; defaults to a read-only dry run.
 * See SEED_CLINICAL_WORKFLOWS.md before using --commit. Never use on production.
 * Uses OpenEMR QueryUtils/ADODB with the selected site's credentials, without bootstrapping
 * globals.php (which can run unrelated application initialization side effects).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Common\Session\SessionWrapperFactory;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Small adapter keeping every query in OpenEMR's native exception-safe DAL. */
final class WorkflowStatement
{
    private $result;

    public function __construct(private string $sql) {}

    public function execute(array $params = []): void
    {
        // Explicit fixture import: no SQL audit side effects, including during dry-run.
        $this->result = QueryUtils::sqlStatementThrowException($this->sql, $params, noLog: true);
    }

    public function fetchColumn(): mixed
    {
        $row = $this->result->FetchRow();
        return $row === false ? false : array_values($row)[0];
    }

    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
    {
        $rows = [];
        while ($row = $this->result->FetchRow()) {
            $rows[] = $mode === PDO::FETCH_COLUMN ? array_values($row)[0] : $row;
        }
        return $rows;
    }
}

final class WorkflowDatabase
{
    private bool $transaction = false;
    public function prepare(string $sql): WorkflowStatement { return new WorkflowStatement($sql); }
    public function query(string $sql): WorkflowStatement
    {
        $statement = $this->prepare($sql);
        $statement->execute();
        return $statement;
    }
    public function exec(string $sql): void { $this->query($sql); }
    public function lastInsertId(): int { return (int) $GLOBALS['adodb']['db']->Insert_ID(); }
    public function beginTransaction(): void { $this->exec('START TRANSACTION'); $this->transaction = true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): void { $this->exec('COMMIT'); $this->transaction = false; }
    public function rollBack(): void { $this->exec('ROLLBACK'); $this->transaction = false; }
}

function workflowQuery(WorkflowDatabase $db, string $sql, array $params = []): WorkflowStatement
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function workflowUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return $bytes;
}

function workflowConnection(string $site): WorkflowDatabase
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
    // Refuse split credential databases; identity writes must share one transaction.
    foreach (['host', 'port', 'login', 'pass', 'dbase'] as $key) {
        $secureKey = 'secure_' . $key;
        if (!empty($$secureKey) && (string) $$secureKey !== (string) $$key) {
            throw new RuntimeException('Split authentication databases are not supported by this local fixture script.');
        }
    }
    require_once __DIR__ . '/vendor/autoload.php';
    OEGlobalsBag::getInstance()->set('OE_SITE_DIR', __DIR__ . '/sites/' . $site);
    OEGlobalsBag::getInstance()->set('web_root', '');
    SessionWrapperFactory::getInstance()->setActiveSession(new Session(new MockArraySessionStorage()));
    require_once __DIR__ . '/library/sql.inc.php';
    require_once __DIR__ . '/library/sanitize.inc.php';
    return new WorkflowDatabase();
}

$db = null;
$lock = false;
try {
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--(help|commit|confirm-local|no-other-writers|site|patients|batch|date|inbox-user)(?:=(.*))?$/D', $arg, $match)) {
            throw new RuntimeException('Unknown argument. Run with --help.');
        }
        $key = $match[1];
        $takesValue = in_array($key, ['site', 'patients', 'batch', 'date', 'inbox-user'], true);
        if (array_key_exists($key, $options) || $takesValue !== isset($match[2])) {
            throw new RuntimeException('Repeated option or incorrect option value. Run with --help.');
        }
        $options[$key] = $match[2] ?? true;
    }
    if (isset($options['help'])) {
        echo "Usage: php seed_clinical_workflows.php [--site=default] [--patients=8] [--batch=clinical1]\n";
        echo "       [--date=YYYY-MM-DD] [--inbox-user=existing-username]\n";
        echo "       [--commit --confirm-local --no-other-writers]\n";
        echo "Commit requires OPENEMR_SEED_PASSWORD in the environment (16-72 ASCII characters,\n";
        echo "including upper/lowercase letters, a digit and a symbol). Never pass it on the command line.\n";
        echo "Default: read-only schema/fixture preflight. Commit requires a local test database\n";
        echo "backup and no concurrent application writers. See SEED_CLINICAL_WORKFLOWS.md.\n";
        exit(0);
    }
    $site = $options['site'] ?? 'default';
    $batch = $options['batch'] ?? 'clinical1';
    $count = $options['patients'] ?? '8';
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $site) || !is_file(__DIR__ . "/sites/$site/sqlconf.php")) {
        throw new RuntimeException('Invalid or missing site.');
    }
    if (!preg_match('/^[a-z0-9]{1,12}$/D', $batch) || !ctype_digit($count) || (int) $count < 5 || (int) $count > 10) {
        throw new RuntimeException('Use a batch of 1-12 lowercase letters/digits and 5-10 patients.');
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
    $password = getenv('OPENEMR_SEED_PASSWORD');
    if ($commit && (!is_string($password) || !preg_match('/^[\x21-\x7e]{16,72}$/D', $password)
        || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password))) {
        throw new RuntimeException('Set a strong OPENEMR_SEED_PASSWORD; see --help.');
    }
    $marker = 'DUMMY-' . $batch;
    $db = workflowConnection($site);
    // Session-only settings: never alter persisted OpenEMR or server configuration.
    $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'oe-seed-' . substr(hash('sha256', $database), 0, 40);
    $lock = (int) workflowQuery($db, 'SELECT GET_LOCK(?, 0)', [$lockName])->fetchColumn() === 1;
    if (!$lock) {
        throw new RuntimeException('Another seed process is running.');
    }
    $tables = ['facility', 'users', 'users_secure', 'groups', 'gacl_aro', 'gacl_groups_aro_map',
        'gacl_aro_seq', 'patient_data', 'openemr_postcalendar_events', 'form_encounter',
        'forms', 'uuid_registry', 'sequences', 'medex_recalls', 'patient_reminders',
        'pnotes', 'dated_reminders', 'dated_reminders_link'];
    $schema = [];
    foreach ($tables as $table) {
        $engine = workflowQuery($db, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchColumn();
        if (strtoupper((string) $engine) !== 'INNODB') {
            throw new RuntimeException("$table must exist and use InnoDB for atomic rollback.");
        }
        // Triggers could mutate tables outside the documented allowlist.
        if ((int) workflowQuery($db, 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$table])->fetchColumn()) {
            throw new RuntimeException("Refusing to write $table: custom triggers require manual review.");
        }
        $schema[$table] = array_column($db->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll(), null, 'Field');
    }
    foreach ([['users', 'username', "dummy_$batch" . '_%'], ['patient_data', 'lname', "$marker %"], ['facility', 'name', "$marker %"]] as [$table, $column, $value]) {
        if (workflowQuery($db, "SELECT 1 FROM `$table` WHERE `$column` LIKE ? LIMIT 1", [$value])->fetchColumn()) {
            throw new RuntimeException("Batch $batch already exists. Refusing duplicate or partial reseeding.");
        }
    }
    $category = workflowQuery($db, "SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_constant_id = 'office_visit' AND pc_active = 1")->fetchColumn();
    if ($category === false) {
        throw new RuntimeException('An active office_visit calendar category is required.');
    }
    $category = (int) $category;
    $role = workflowQuery($db, "SELECT id FROM gacl_aro_groups WHERE value = 'doc' AND name = 'Physicians'")->fetchColumn();
    $practice = workflowQuery($db, "SELECT name FROM `groups` WHERE name IS NOT NULL AND name <> '' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$role || !$practice || !workflowQuery($db, "SELECT id FROM gacl_aro_sections WHERE value = 'users'")->fetchColumn()) {
        throw new RuntimeException('Existing Physicians role, users ACL section and practice group are required.');
    }
    $action = workflowQuery($db, "SELECT a.category, a.item FROM rule_action_item a
        JOIN list_options c ON c.list_id = 'rule_action_category' AND c.option_id = a.category
        JOIN list_options i ON i.list_id = 'rule_action' AND i.option_id = a.item
        WHERE a.category = 'act_cat_measure' AND a.item = 'act_bp'")->fetchAll();
    if (count($action) !== 1) {
        throw new RuntimeException('Existing blood-pressure reminder action act_cat_measure/act_bp is required.');
    }
    foreach (['rule_reminder_due_opt' => ['due', 'past_due', 'soon_due'],
        'note_type' => ['Chart Note', 'Patient Reminders'], 'message_status' => ['New', 'Read', 'Done']] as $list => $values) {
        foreach ($values as $value) {
            if (!workflowQuery($db, 'SELECT 1 FROM list_options WHERE list_id = ? AND option_id = ?', [$list, $value])->fetchColumn()) {
                throw new RuntimeException("Missing existing list value $list/$value.");
            }
        }
    }
    $observer = null;
    if (isset($options['inbox-user'])) {
        $observers = workflowQuery($db, 'SELECT u.id, u.username FROM users u JOIN users_secure s ON s.id = u.id AND s.username = u.username WHERE u.username = ? AND u.active = 1', [$options['inbox-user']])->fetchAll();
        if (count($observers) !== 1) {
            throw new RuntimeException('--inbox-user must be an existing active login account.');
        }
        $observer = $observers[0];
    }
    // Only read hash/password policy settings. No persisted configuration changes.
    OEGlobalsBag::getInstance()->set('gbl_auth_hash_algo', 'DEFAULT');
    foreach (workflowQuery($db, "SELECT gl_name, gl_value FROM globals WHERE gl_name LIKE 'gbl_auth_%' OR gl_name = 'password_min_length'")->fetchAll() as $setting) {
        OEGlobalsBag::getInstance()->set($setting['gl_name'], $setting['gl_value']);
    }
    if ($commit && strlen($password) < OEGlobalsBag::getInstance()->getInt('password_min_length', 0)) {
        throw new RuntimeException('Password is shorter than the configured site minimum.');
    }
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
    foreach (['Main Clinical Test Facility'] as $i => $name) {
        $facilityNames[$i] = "$marker $name";
        $facilities[$i] = $add('facility', [
            'uuid' => workflowUuid(), 'name' => $facilityNames[$i], 'street' => (100 + $i) . ' Example Avenue',
            'city' => 'Springfield', 'state' => 'IL', 'postal_code' => '62701', 'country_code' => 'US',
            'phone' => '217-555-0100', 'email' => "clinic$i.$batch@example.invalid", 'pos_code' => 11,
            'service_location' => 1, 'billing_location' => 1, 'primary_business_entity' => 0,
            'color' => $i ? '#387D50' : '#376BA3', 'inactive' => 0,
        ]);
    }
    $providers = [];
    $usernames = [];
    foreach ([['Maya', 'Patel'], ['Daniel', 'Brooks']] as $i => [$first, $last]) {
        $usernames[$i] = "dummy_{$batch}_$i";
        $providers[$i] = $add('users', [
            'uuid' => workflowUuid(), 'username' => $usernames[$i],
            'password' => 'NoLongerUsed', 'fname' => $first, 'lname' => "$marker $last",
            'authorized' => 1, 'active' => 1, 'calendar' => 1,
            'facility_id' => $ref($facilities[0]), 'facility' => $facilityNames[0],
            'billing_facility_id' => $ref($facilities[0]),
            'billing_facility' => $facilityNames[0],
            'title' => 'Dr.', 'specialty' => 'Family Medicine',
            'info' => "$marker synthetic practitioner; existing Physicians role.",
            'email' => "staff$i.$batch@example.invalid", 'phone' => '217-555-0101',
        ]);
        // Bind credentials and practice/ACL membership only to these NEW users.
        foreach (['users_secure' => 'username', 'gacl_aro' => 'value'] as $table => $field) {
            if (workflowQuery($db, "SELECT 1 FROM `$table` WHERE `$field` = ?", [$usernames[$i]])->fetchColumn()) {
                throw new RuntimeException('A requested username already has credential/ACL records.');
            }
        }
        $add('users_secure', ['id' => $ref($providers[$i]), 'username' => $usernames[$i],
            'password' => ['password' => true], 'last_update_password' => date('Y-m-d H:i:s')]);
        $add('groups', ['name' => $practice, 'user' => $usernames[$i]]);
        $add('gacl_aro', ['id' => ['aro' => $i], 'section_value' => 'users', 'value' => $usernames[$i],
            'name' => "$first $marker $last", 'order_value' => 10, 'hidden' => 0]);
        $add('gacl_groups_aro_map', ['group_id' => (int) $role, 'aro_id' => ['aro' => $i]]);
    }
    $maxPid = (int) $db->query('SELECT COALESCE(MAX(pid), 0) FROM patient_data')->fetchColumn();
    if ($maxPid + $count > 2147483647) {
        throw new RuntimeException('New patient IDs exceed the recall r_pid integer range.');
    }
    $names = [['Amelia', 'Turner', 'Female'], ['Noah', 'Wilson', 'Male'], ['Ava', 'Shah', 'Female'],
        ['Liam', 'Garcia', 'Male'], ['Isabella', 'Reed', 'Female'], ['Ethan', 'Kim', 'Male'],
        ['Charlotte', 'Brown', 'Female'], ['Oliver', 'Singh', 'Male']];
    $reasons = ['Annual wellness visit', 'Blood pressure follow-up', 'Seasonal allergy review', 'Routine health review'];
    for ($i = 0; $i < $count; $i++) {
        $pid = $maxPid + $i + 1;
        if (workflowQuery($db, 'SELECT 1 FROM patient_data WHERE pubpid = ? LIMIT 1', [(string) $pid])->fetchColumn()) {
            throw new RuntimeException("Public patient ID $pid already exists; refusing to reuse it.");
        }
        [$first, $last, $sex] = $names[$i % count($names)];
        $p = $i % 2;
        $f = 0;
        $add('patient_data', [
            'uuid' => workflowUuid(), 'pid' => $pid, 'pubpid' => (string) $pid,
            'fname' => $first, 'lname' => "$marker $last", 'sex' => $sex,
            'DOB' => $base->modify('-' . (22 + ($i * 7) % 58) . ' years')->format('Y-m-d'),
            'street' => (200 + $i) . ' Example Avenue', 'city' => 'Springfield', 'state' => 'IL',
            'postal_code' => '62701', 'country_code' => 'US', 'phone_home' => sprintf('217-555-%04d', 100 + $i % 100),
            'email' => "patient$i.$batch@example.invalid", 'providerID' => $ref($providers[$p]),
            'date' => $now, 'regdate' => $base->format('Y-m-d'),
            'hipaa_allowemail' => 'NO', 'hipaa_allowsms' => 'NO', 'hipaa_voice' => 'NO', 'hipaa_mail' => 'NO',
        ]);
        // One past visit and one future appointment, staggered to avoid overlaps.
        $slot = intdiv($i, 2);
        $day = intdiv($slot, 12);
        $minutes = ($slot % 12) * 30;
        foreach ([-14 - $day, 7 + $day] as $visitIndex => $offset) {
            $start = $base->modify("$offset days")->setTime(9, 0)->modify("+$minutes minutes");
            $end = $start->modify('+30 minutes');
            $reason = $reasons[$i % count($reasons)];
            $add('openemr_postcalendar_events', [
                'uuid' => workflowUuid(), 'pc_pid' => (string) $pid, 'pc_aid' => $ref($providers[$p]),
                'pc_informant' => $ref($providers[$p]), 'pc_catid' => $category, 'pc_multiple' => 0,
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
                'uuid' => workflowUuid(), 'pid' => $pid, 'encounter' => $encounter,
                'date' => $start->format('Y-m-d H:i:s'), 'date_end' => $end->format('Y-m-d H:i:s'),
                'reason' => "$marker $reason", 'facility' => $facilityNames[$f],
                'facility_id' => $ref($facilities[$f]), 'billing_facility' => $ref($facilities[$f]),
                'provider_id' => $ref($providers[$p]), 'pc_catid' => $category, 'class_code' => 'AMB', 'pos_code' => 11,
            ]);
            $add('forms', [
                'date' => $start->format('Y-m-d H:i:s'), 'encounter' => $encounter, 'pid' => $pid,
                'form_name' => 'New Patient Encounter', 'formdir' => 'newpatient',
                'form_id' => $ref($encounterRow), 'user' => $usernames[$p], 'groupname' => $practice,
                'authorized' => 1, 'deleted' => 0, 'provider_id' => $ref($providers[$p]),
            ]);
        }
        $dueOffset = [-7, 0, 3][$i % 3];
        $due = $base->modify("$dueOffset days")->format('Y-m-d');
        $dueStatus = ['past_due', 'due', 'soon_due'][$i % 3];
        $add('medex_recalls', ['r_PRACTID' => 0, 'r_pid' => $pid, 'r_eventDate' => $due,
            'r_facility' => $ref($facilities[0]), 'r_provider' => $ref($providers[$p]),
            'r_reason' => "$marker blood pressure follow-up (synthetic)"]);
        $add('patient_reminders', ['active' => 1, 'pid' => $pid, 'due_status' => $dueStatus,
            'category' => $action[0]['category'], 'item' => $action[0]['item'],
            'date_created' => $base->modify('-14 days')->format('Y-m-d H:i:s')]);
        // A chart note and two patient-linked internal messages in opposite directions.
        $add('pnotes', ['date' => $now, 'pid' => $pid, 'user' => $usernames[$p],
            'groupname' => $practice, 'activity' => 0, 'authorized' => 1, 'deleted' => 0,
            'title' => 'Chart Note', 'body' => "$marker: Synthetic visit reviewed; arrange blood pressure follow-up.",
            'assigned_to' => '', 'message_status' => 'Done']);
        foreach ([$p, 1 - $p] as $direction => $sender) {
            $recipient = 1 - $sender;
            $add('pnotes', ['date' => $now, 'pid' => $pid, 'user' => $usernames[$sender],
                'groupname' => $practice, 'activity' => 1, 'authorized' => 1, 'deleted' => 0,
                'title' => 'Patient Reminders', 'assigned_to' => $usernames[$recipient],
                'message_status' => $direction === 0 ? 'New' : 'Read',
                'body' => "$now ({$usernames[$sender]} to {$usernames[$recipient]}) $marker: "
                    . ($direction === 0 ? "Please review follow-up due $due for $first $last." : 'Follow-up review acknowledged; please arrange the visit.')]);
        }
        $reminder = $add('dated_reminders', ['dr_from_ID' => $ref($providers[$p]),
            'dr_message_text' => "$marker: Review blood pressure follow-up for $first $last.",
            'dr_message_sent_date' => $now, 'dr_message_due_date' => $due, 'pid' => $pid,
            'message_priority' => $dueOffset < 0 ? 1 : 3, 'message_processed' => 0, 'dr_processed_by' => 0]);
        $add('dated_reminders_link', ['dr_id' => $ref($reminder), 'to_id' => $ref($providers[1 - $p])]);
        if ($observer) {
            $add('pnotes', ['date' => $now, 'pid' => $pid, 'user' => $usernames[$p],
                'groupname' => $practice, 'activity' => 1, 'authorized' => 1, 'deleted' => 0,
                'title' => 'Patient Reminders', 'assigned_to' => $observer['username'], 'message_status' => 'New',
                'body' => "$marker: Test inbox notification for $first $last; recall due $due."]);
            $add('dated_reminders_link', ['dr_id' => $ref($reminder), 'to_id' => (int) $observer['id']]);
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
    $aroSequence = $db->query('SELECT id FROM gacl_aro_seq')->fetchAll(PDO::FETCH_COLUMN);
    $maxAro = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM gacl_aro')->fetchColumn();
    if (count($aroSequence) !== 1 || (int) $aroSequence[0] < $maxAro || (int) $aroSequence[0] + 2 > 2147483647) {
        throw new RuntimeException('ACL identity sequence is inconsistent or exhausted; repair separately.');
    }
    $counts = array_count_values(array_column($plan, 'table'));
    echo ($commit ? 'COMMIT' : 'DRY RUN') . " | site=$site | database=$database | batch=$batch | date=$dateText\n";
    foreach ($counts as $table => $total) {
        echo "  $table: $total new rows\n";
    }
    echo "  sequences: advance by $count on commit only\n";
    echo "  gacl_aro_seq: advance by 2 on commit only\n";
    echo '  Provider logins: ' . implode(', ', $usernames) . " (existing Physicians role)\n";
    if (!$commit) {
        echo "Preflight passed. No data was written; SQL execution/constraints are only exercised on commit.\n";
    } else {
        $db->beginTransaction();
        $currentSequence = $db->query('SELECT id FROM sequences FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if ($currentSequence !== $sequenceRows || (int) $db->query('SELECT COALESCE(MAX(pid), 0) FROM patient_data')->fetchColumn() !== $maxPid) {
            throw new RuntimeException('Data changed during preflight. Stop other writers and rerun.');
        }
        if ($db->query('SELECT id FROM gacl_aro_seq FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN) !== $aroSequence) {
            throw new RuntimeException('ACL identity sequence changed during preflight; rerun when idle.');
        }
        $aroIds = [];
        for ($i = 0; $i < 2; $i++) {
            $db->exec('UPDATE gacl_aro_seq SET id = LAST_INSERT_ID(id + 1)');
            $aroIds[$i] = (int) $db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
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
                    $value = match (true) {
                        isset($value['ref']) => $ids[$value['ref']],
                        isset($value['sequence']) => $encounters[$value['sequence']],
                        isset($value['aro']) => $aroIds[$value['aro']],
                        isset($value['password']) => (new AuthHash())->passwordHash($password),
                        default => throw new RuntimeException('Unknown fixture reference.'),
                    };
                }
            }
            unset($value);
            $columns = '`' . implode('`,`', array_keys($row)) . '`';
            $placeholders = implode(',', array_fill(0, count($row), '?'));
            workflowQuery($db, "INSERT INTO `{$record['table']}` ($columns) VALUES ($placeholders)", array_values($row));
            $ids[$record['key']] = (int) $db->lastInsertId();
        }
        // Verify patient/provider/facility/form joins before committing any rows.
        $linked = workflowQuery($db, "SELECT COUNT(*) FROM form_encounter e
            JOIN patient_data p ON p.pid = e.pid AND BINARY p.pubpid = BINARY CAST(p.pid AS CHAR)
            JOIN users u ON u.id = e.provider_id
            JOIN facility f ON f.id = e.facility_id
            JOIN facility b ON b.id = e.billing_facility
            JOIN forms r ON r.form_id = e.id AND r.pid = e.pid AND r.encounter = e.encounter
                AND r.formdir = 'newpatient' AND r.deleted = 0 AND r.provider_id = u.id
            WHERE p.pid > ? AND p.pid <= ?", [$maxPid, $maxPid + $count])->fetchColumn();
        $appointments = workflowQuery($db, "SELECT COUNT(*) FROM openemr_postcalendar_events a
            JOIN patient_data p ON a.pc_pid = p.pid JOIN users u ON a.pc_aid = u.id
            JOIN facility f ON a.pc_facility = f.id JOIN facility b ON a.pc_billing_location = b.id
            WHERE p.pid > ? AND p.pid <= ?", [$maxPid, $maxPid + $count])->fetchColumn();
        if ((int) $linked !== $count || (int) $appointments !== 2 * $count) {
            throw new RuntimeException('Relationship verification failed.');
        }
        $checks = [
            [$count, "SELECT COUNT(*) FROM medex_recalls r JOIN patient_data p ON p.pid=r.r_pid
                JOIN users u ON u.id=r.r_provider JOIN facility f ON f.id=r.r_facility
                WHERE p.pid > ? AND p.pid <= ?"],
            [$count, "SELECT COUNT(*) FROM patient_reminders r JOIN patient_data p ON p.pid=r.pid
                JOIN rule_action_item a ON a.category=r.category AND a.item=r.item
                WHERE p.pid > ? AND p.pid <= ? AND r.active=1"],
            [$count * ($observer ? 3 : 2), "SELECT COUNT(*) FROM pnotes n JOIN patient_data p ON p.pid=n.pid
                JOIN users sender ON sender.username=n.user JOIN users recipient ON recipient.username=n.assigned_to
                WHERE p.pid > ? AND p.pid <= ? AND n.activity=1 AND n.deleted=0"],
            [$count * ($observer ? 2 : 1), "SELECT COUNT(*) FROM dated_reminders r JOIN patient_data p ON p.pid=r.pid
                JOIN dated_reminders_link l ON l.dr_id=r.dr_id JOIN users u ON u.id=l.to_id
                JOIN users sender ON sender.id=r.dr_from_ID
                WHERE p.pid > ? AND p.pid <= ? AND r.message_processed=0"],
        ];
        foreach ($checks as [$expected, $sql]) {
            if ((int) workflowQuery($db, $sql, [$maxPid, $maxPid + $count])->fetchColumn() !== $expected) {
                throw new RuntimeException('Recall/message relationship verification failed.');
            }
        }
        foreach ($usernames as $username) {
            $account = workflowQuery($db, 'SELECT s.password FROM users u JOIN users_secure s ON s.id=u.id AND s.username=u.username
                JOIN gacl_aro a ON a.section_value=\'users\' AND a.value=u.username
                JOIN gacl_groups_aro_map m ON m.aro_id=a.id AND m.group_id=?
                JOIN `groups` g ON g.user=u.username AND g.name=?
                WHERE u.username=? AND u.active=1 AND u.authorized=1', [(int) $role, $practice, $username])->fetchAll();
            if (count($account) !== 1 || !AuthHash::passwordVerify($password, $account[0]['password'])) {
                throw new RuntimeException('Provider credential/role verification failed.');
            }
        }
        $db->commit();
        echo "Committed. Patient PIDs: " . ($maxPid + 1) . '-' . ($maxPid + $count) . ". Search for $marker.\n";
    }
} catch (Throwable $error) {
    if ($db instanceof WorkflowDatabase && $db->inTransaction()) {
        $db->rollBack();
    }
    // Database exceptions can include bound values/credentials. Never print these.
    $message = $error instanceof \OpenEMR\Common\Database\SqlQueryException || $error instanceof \mysqli_sql_exception
        ? 'Database operation failed. Check schema, permissions and connectivity; no seed rows were committed.'
        : $error->getMessage();
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
} finally {
    if ($lock && $db instanceof WorkflowDatabase) {
        workflowQuery($db, 'SELECT RELEASE_LOCK(?)', [$lockName]);
    }
}

