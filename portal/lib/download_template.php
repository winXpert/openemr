<?php

/**
 * Document Template Rendering front end.
 *
 * @package OpenEMR
 * @author  Jerry Padgett <sjpadgett@gmail.com>
 * Copyright (C) 2023-2024 Jerry Padgett <sjpadgett@gmail.com>
 * @link    https://www.open-emr.org
 */

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\DocumentTemplates\DocumentTemplateRender;

// Need access to classes, so run autoloader now instead of in globals.php.
require_once(__DIR__ . "/../../vendor/autoload.php");
$globalsBag = OEGlobalsBag::getInstance();

$postPid = $_POST['pid'] ?? null;
$is_module = $_POST['isModule'] ?? 0;
if ($is_module) {
    require_once(__DIR__ . '/../../interface/globals.php');
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    if (!AclMain::aclCheckCore('patients', 'docs')) {
        AccessDeniedHelper::deny('download_template.php (isModule): patients/docs not granted');
    }
    CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);
    // Staff can open the editor for a patient other than the currently active
    // chart. Authorize that explicit patient without changing the chart session.
    $requestedPid = $postPid !== null && $postPid !== '' ? $postPid : $session->get('pid');
    $pid = filter_var($requestedPid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($pid === false) {
        http_response_code(400);
        exit(xlt('No patient selected.'));
    }
    $patient = sqlQuery('SELECT pid, squad FROM patient_data WHERE pid = ?', [$pid]);
    if (empty($patient)) {
        http_response_code(404);
        exit(xlt('Patient not found.'));
    }
    if (!empty($patient['squad']) && !AclMain::aclCheckCore('squads', $patient['squad'])) {
        AccessDeniedHelper::deny('download_template.php (isModule): patient squad access not granted');
    }
} else {
    require_once(__DIR__ . "/../verify_session.php");
    $session = SessionWrapperFactory::getInstance()->getPortalSession();
    // ensure patient is bootstrapped (if sent)
    if (!empty($_POST['pid'])) {
        if ($_POST['pid'] != $session->get('pid')) {
            echo xlt("illegal Action");
            SessionWrapperFactory::getInstance()->destroyPortalSession();
            exit;
        }
    }
}

$form_id = $_POST['template_id'] ?? null;
// Staff patient context was authorized above; portal context always comes from
// the authenticated portal session, never from an unvalidated POST value.
if (!$is_module) {
    $pid = $session->get('pid');
}
$user = $session->get('authUserID') ?? $session->get('sessionUser'); // session 'sessionUser' is '-patient-'
$prepared_doc = xlt("Error! Missing template or template unavailable.");
if (!empty($form_id)) {
    $templateRender = new DocumentTemplateRender($pid, $user);
    $prepared_doc = $templateRender->doRender($form_id, null, null);

    if (!$prepared_doc) {
        throw new RuntimeException(xlt("Fetch failed in download template. No content to render in template render."));
    }
// add a version to template
    if (stripos($prepared_doc, 'portal_version') === false) {
        $prepared_doc .= "<input style='display: none;' id='portal_version' name='portal_version' type='hidden' value='New' />\n";
    }
}
echo $prepared_doc;
exit;
