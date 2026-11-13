<?php

/**
 * SMART launch URI. OpenEMR's patient portal opens it from Health Apps with iss, aud and an
 * opaque launch; the app sends the patient to sign in and comes back to callback.php.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use OpenEMR\VisitPrep\Config;
use OpenEMR\VisitPrep\Http;
use OpenEMR\VisitPrep\Smart;
use OpenEMR\VisitPrep\View;

start_page();

$issuer = is_string($_GET['iss'] ?? null) ? Config::normalizeIssuer($_GET['iss']) : '';
$launch = is_string($_GET['launch'] ?? null) ? $_GET['launch'] : '';
if ($issuer === '' || $launch === '') {
    View::error(400, 'Open Visit Prep from your patient portal', 'This app starts from the Health Apps list in your '
        . 'patient portal. Sign in to the portal, open Health Apps, and choose Visit Prep.');
}
$client = Config::clientFor($issuer);
if ($client === null) {
    // Refusing unknown issuers keeps a forged ?iss= from ever receiving a code or token.
    View::error(400, 'Not set up for this practice', "Visit Prep is not registered with {$issuer}.");
}

try {
    $configuration = (new Smart(new Http(Config::verifyTls())))->discover($issuer);
} catch (Throwable $exception) {
    View::error(502, 'Could not reach your practice', $exception->getMessage());
}

$state = Smart::base64Url(random_bytes(32));
$verifier = Smart::newVerifier();
$_SESSION['pending'][$state] = [
    'issuer' => $issuer,
    'verifier' => $verifier,
    'configuration' => $configuration,
    'created' => time(),
];
// Keep only the last few unfinished sign-ins.
$_SESSION['pending'] = array_slice($_SESSION['pending'], -5, null, true);

header('Location: ' . Smart::authorizeUrl($configuration, (string)$client['client_id'], $issuer, (string)($client['scope'] ?? Smart::SCOPES), $state, $verifier, $launch));
exit;
