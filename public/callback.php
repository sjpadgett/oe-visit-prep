<?php

/**
 * OAuth redirect URI: checks the state, exchanges the code (PKCE plus the client secret) and keeps
 * the token in the session.
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

$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
$pending = $_SESSION['pending'][$state] ?? null;
unset($_SESSION['pending'][$state]);
if (!is_array($pending) || time() - (int)$pending['created'] > 900) {
    View::error(400, 'Sign-in expired', 'Open Visit Prep again from Health Apps in your patient portal.');
}
if (isset($_GET['error'])) {
    View::error(400, 'Sign-in was not completed', is_string($_GET['error_description'] ?? null) ? $_GET['error_description'] : (string)$_GET['error']);
}
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$client = Config::clientFor((string)$pending['issuer']);
if ($code === '' || $client === null) {
    View::error(400, 'Sign-in failed', 'The practice did not return a sign-in code.');
}

try {
    $token = (new Smart(new Http(Config::verifyTls())))->exchangeCode((array)$pending['configuration'], $client, $code, (string)$pending['verifier']);
} catch (Throwable $exception) {
    View::error(502, 'Sign-in failed', $exception->getMessage());
}
if (!is_string($token['patient'] ?? null) || $token['patient'] === '') {
    View::error(400, 'No patient', 'The practice did not say whose record this is. Open Visit Prep from your patient portal.');
}

session_regenerate_id(true);
$_SESSION['visit'] = [
    'issuer' => $pending['issuer'],
    'token' => $token['access_token'],
    'scope' => (string)($token['scope'] ?? ''),
    'patient' => $token['patient'],
    'expires_at' => time() + (is_numeric($token['expires_in'] ?? null) ? (int)$token['expires_in'] : 3600) - 30,
    'csrf' => bin2hex(random_bytes(16)),
];
header('Location: index.php');
exit;
