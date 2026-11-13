<?php

/**
 * Registers Visit Prep with an OpenEMR (OAuth2 dynamic client registration) and saves the client.
 *
 *   php bin/register.php --iss=https://ehr.example/apis/default/fhir
 *
 * Asks only for the scopes the server publishes. OpenEMR creates the client disabled: enable it
 * in Admin > System > API Clients, and the patient portal lists it under Health Apps.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use OpenEMR\VisitPrep\Config;
use OpenEMR\VisitPrep\Http;
use OpenEMR\VisitPrep\Smart;

$options = getopt('', ['iss:', 'help']);
if (!is_string($options['iss'] ?? null) || isset($options['help'])) {
    fwrite(STDERR, "Usage: php bin/register.php --iss=<FHIR base URL>\n");
    exit(2);
}
$issuer = Config::normalizeIssuer($options['iss']);
$http = new Http(Config::verifyTls());

try {
    $configuration = (new Smart($http))->discover($issuer);
    $offered = is_array($configuration['scopes_supported'] ?? null) ? $configuration['scopes_supported'] : null;
    $wanted = explode(' ', Smart::SCOPES);
    $scopes = $offered === null ? $wanted : array_values(array_filter($wanted, static fn(string $s): bool => in_array($s, $offered, true)));
    $dropped = array_values(array_diff($wanted, $scopes));
    foreach (Smart::REQUIRED as $required) {
        if (!in_array($required, $scopes, true)) {
            throw new RuntimeException("The server does not publish {$required}; Visit Prep needs it. Is the FHIR API on?");
        }
    }
    $endpoint = is_string($configuration['registration_endpoint'] ?? null) && $configuration['registration_endpoint'] !== ''
        ? $configuration['registration_endpoint']
        // OpenEMR publishes registration in its OAuth2 discovery; it sits beside authorize.
        : (string)preg_replace('~/authorize/?$~', '/registration', (string)$configuration['authorization_endpoint']);
    $payload = [
        'application_type' => 'private',
        'client_name' => Config::string('app_name'),
        'contacts' => [Config::string('contact')],
        'redirect_uris' => [Config::redirectUri()],
        // OpenEMR stores initiate_login_uri as the SMART launch URI the portal opens.
        'initiate_login_uri' => Config::launchUri(),
        'launch_uris' => [Config::launchUri()],
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'client_secret_post',
        'scope' => implode(' ', $scopes),
    ];
    $r = $http->request('POST', $endpoint, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $client = json_decode($r['body'], true);
    if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($client) || !is_string($client['client_id'] ?? null)) {
        $reason = is_array($client) ? (string)($client['error_description'] ?? $client['error'] ?? '') : substr(strip_tags($r['body']), 0, 300);
        throw new RuntimeException("Registration at {$endpoint} failed (HTTP {$r['status']}): {$reason}");
    }
    Config::saveClient($issuer, array_filter([
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'] ?? null,
        'scope' => implode(' ', $scopes),
        'registration_client_uri' => $client['registration_client_uri'] ?? null,
        'registration_access_token' => $client['registration_access_token'] ?? null,
        'registered' => gmdate('c'),
    ], static fn($value) => $value !== null));
} catch (Throwable $exception) {
    fwrite(STDERR, '✗ ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "✓ Registered with {$issuer}: client {$client['client_id']}\n"
    . '  scopes: ' . implode(' ', $scopes) . "\n"
    . ($dropped !== [] ? '  not published by this server, left out: ' . implode(' ', $dropped) . "\n" : '')
    . (in_array('patient/QuestionnaireResponse.cu', $scopes, true) ? '' : "  Patients can't send yet: turn on Patient App FHIR Writes and QuestionnaireResponse in OpenEMR, then register again.\n")
    . '  launch URI:   ' . Config::launchUri() . "\n"
    . '  redirect URI: ' . Config::redirectUri() . "\n"
    . "Next: enable the client in OpenEMR (Admin > System > API Clients). The patient portal then lists it under Health Apps.\n");
