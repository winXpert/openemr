<?php

/**
 * Display patient notes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
$srcdir = \OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir();
$session = \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession();
$pid = $session->get('pid', 0);
require_once($srcdir . "/options.inc.php");

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;

// Check authorization.
$thisauth = AclMain::aclCheckCore('patients', 'notes');
if (!$thisauth) {
    AccessDeniedHelper::deny('Unauthorized access to patient notes print');
}

$noteid = (int) ($_REQUEST['noteid'] ?? 0);
$nrow = $noteid > 0 ? getPnoteById($noteid, 'pid,title,assigned_to,activity,body') : false;
if (empty($nrow)) {
    http_response_code(404);
    exit(xlt('Note not found.'));
}

// Printing from Messages does not require a patient selected in the session.
// The note itself is the authoritative source of the patient association.
$pid = (int) $nrow['pid'];
$ptname = '';
if ($pid > 0) {
    $prow = getPatientData($pid, "squad, title, fname, mname, lname");
    if (empty($prow)) {
        http_response_code(404);
        exit(xlt('Patient not found.'));
    }
    if ($prow['squad'] && !AclMain::aclCheckCore('squads', $prow['squad'])) {
        AccessDeniedHelper::deny('Not authorized for squad: ' . $prow['squad']);
    }
    $ptname = trim($prow['title'] . ' ' . $prow['fname'] . ' ' . $prow['mname'] . ' ' . $prow['lname']);
} elseif (!checkPnotesNoteId($noteid, $session->get('authUser'))) {
    AccessDeniedHelper::deny('Message is not assigned to the current user');
}

$title = $nrow['title'];
$assigned_to = $nrow['assigned_to'];
$activity = $nrow['activity'];
$body = $nrow['body'];
?>
<html>
<head>
<?php Header::setupHeader(); ?>
</head>

<body class="body_top">

<p><?php echo "<b>" .
  generate_display_field(['data_type' => '1','list_id' => 'note_type'], $title) .
  "</b>" . ($ptname !== '' ? ' ' . xlt('for') . ' ' .
  "<b>" . text($ptname) . "</b>" : ''); ?></p>

<p><?php echo xlt('Assigned To'); ?>: <?php echo text($assigned_to); ?></p>

<p><?php echo xlt('Active{{Note}}'); ?>: <?php echo ($activity ? xlt('Yes') : xlt('No')); ?></p>

<p><?php echo nl2br(text($body)); ?></p>

<script>
opener.top.printLogPrint(window);
</script>

</body>
</html>
