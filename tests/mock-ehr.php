<?php

/**
 * A tiny stand-in for OpenEMR's SMART and FHIR endpoints, enough for tests/e2e.php: discovery,
 * registration, authorize (checks launch, aud and PKCE), token, the patient's records, and
 * QuestionnaireResponse writes held for review the way OpenEMR holds a patient app's writes.
 *
 *   php -S 127.0.0.1:18191 tests/mock-ehr.php     (state in MOCK_STATE_FILE)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$stateFile = (string)getenv('MOCK_STATE_FILE');
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['clients' => [], 'codes' => [], 'tokens' => [], 'responses' => [], 'log' => []];
$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));
};
$json = static function (int $status, array $body) use ($save): never {
    $save();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};
$b64u = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$base = 'http://' . $_SERVER['HTTP_HOST'];
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$state['log'][] = $method . ' ' . $_SERVER['REQUEST_URI'];

$scopes = ['openid', 'fhirUser', 'launch', 'launch/patient', 'patient/Patient.rs', 'patient/MedicationRequest.rs',
    'patient/AllergyIntolerance.rs', 'patient/Condition.rs', 'patient/Appointment.read', 'patient/Questionnaire.rs',
    'patient/QuestionnaireResponse.rs'];
if (getenv('MOCK_WRITES') !== 'off') {
    $scopes[] = 'patient/QuestionnaireResponse.cu';
}

switch (true) {
    case $path === '/fhir/.well-known/smart-configuration':
        $json(200, [
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/registration',
            'scopes_supported' => $scopes,
            'code_challenge_methods_supported' => ['S256'],
        ]);

    case $path === '/oauth/registration' && $method === 'POST':
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $id = 'client-' . count($state['clients']);
        $state['clients'][$id] = ['secret' => 'secret-' . $id, 'redirect_uris' => $body['redirect_uris'] ?? [], 'body' => $body];
        $json(201, ['client_id' => $id, 'client_secret' => 'secret-' . $id]);

    case $path === '/oauth/authorize':
        $client = $state['clients'][$_GET['client_id'] ?? ''] ?? null;
        if ($client === null || !in_array($_GET['redirect_uri'] ?? '', $client['redirect_uris'], true)) {
            $json(400, ['error' => 'invalid_client']);
        }
        if (($_GET['aud'] ?? '') !== $base . '/fhir' || ($_GET['code_challenge_method'] ?? '') !== 'S256' || ($_GET['launch'] ?? '') === '') {
            $json(400, ['error' => 'invalid_request', 'error_description' => 'bad aud, PKCE or launch']);
        }
        $code = bin2hex(random_bytes(8));
        $state['codes'][$code] = ['client_id' => $_GET['client_id'], 'challenge' => $_GET['code_challenge'], 'scope' => $_GET['scope'] ?? '',
            'redirect_uri' => $_GET['redirect_uri'], 'launch' => $_GET['launch']];
        $save();
        header('Location: ' . $_GET['redirect_uri'] . '?' . http_build_query(['code' => $code, 'state' => $_GET['state'] ?? '']));
        exit;

    case $path === '/oauth/token' && $method === 'POST':
        $grant = $state['codes'][$_POST['code'] ?? ''] ?? null;
        unset($state['codes'][$_POST['code'] ?? '']);
        $client = $grant !== null ? ($state['clients'][$grant['client_id']] ?? null) : null;
        if ($grant === null || $client === null || ($_POST['client_secret'] ?? '') !== $client['secret']
            || $b64u(hash('sha256', (string)($_POST['code_verifier'] ?? ''), true)) !== $grant['challenge']) {
            $json(400, ['error' => 'invalid_grant']);
        }
        $access = bin2hex(random_bytes(12));
        $state['tokens'][$access] = $grant['scope'];
        $json(200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => $grant['scope'],
            'patient' => 'p1', 'intent' => 'patient.portal']);
}

if (!isset($state['tokens'][substr((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 7)])) {
    $json(401, ['resourceType' => 'OperationOutcome', 'issue' => [['code' => 'login']]]);
}
$bundle = static fn(array $resources): array => ['resourceType' => 'Bundle', 'type' => 'searchset',
    'entry' => array_map(static fn(array $r): array => ['resource' => $r], array_values($resources))];
$held = static fn(array $r): array => $r + ['meta' => ['tag' => [['system' => 'http://open-emr.org/fhir/CodeSystem/patient-write', 'code' => 'held-for-review']]]];

switch (true) {
    case $path === '/fhir/Patient/p1':
        $json(200, ['resourceType' => 'Patient', 'id' => 'p1', 'name' => [['given' => ['Ada'], 'family' => 'Lovelace']]]);
    case $path === '/fhir/MedicationRequest':
        $json(200, $bundle([
            ['resourceType' => 'MedicationRequest', 'id' => 'm1', 'status' => 'active', 'medicationCodeableConcept' => ['text' => 'Lisinopril 10 mg tablet'],
                'dosageInstruction' => [['text' => 'One tablet daily']]],
            ['resourceType' => 'MedicationRequest', 'id' => 'm2', 'status' => 'active', 'medicationCodeableConcept' => ['coding' => [['display' => 'Metformin 500 mg']]]],
            ['resourceType' => 'MedicationRequest', 'id' => 'm3', 'status' => 'stopped', 'medicationCodeableConcept' => ['text' => 'Old antibiotic']],
        ]));
    case $path === '/fhir/AllergyIntolerance':
        $json(200, $bundle([
            ['resourceType' => 'AllergyIntolerance', 'id' => 'a1', 'code' => ['text' => 'Penicillin'], 'reaction' => [['manifestation' => [['text' => 'Hives']]]]],
        ]));
    case $path === '/fhir/Condition':
        $json(200, $bundle([['resourceType' => 'Condition', 'id' => 'c1', 'code' => ['text' => 'Hypertension']]]));
    case $path === '/fhir/Appointment':
        $json(200, $bundle([['resourceType' => 'Appointment', 'id' => 'ap1', 'status' => 'booked', 'start' => gmdate('Y-m-d\T15:00:00\Z', time() + 86400 * 3), 'description' => 'Annual exam']]));
    case $path === '/fhir/Questionnaire':
        $json(200, getenv('MOCK_NO_QUESTIONNAIRE') === '1' ? $bundle([]) : $bundle([[
            'resourceType' => 'Questionnaire', 'id' => 'q-visit', 'title' => 'Visit Prep: medicines and allergies', 'url' => $base . '/fhir/Questionnaire/q-visit',
            'identifier' => [['system' => 'urn:ietf:rfc:3986', 'value' => 'http://open-emr.org/fhir/Questionnaire/visit-prep']],
        ]]));
    case $path === '/fhir/QuestionnaireResponse' && $method === 'POST':
        if (!str_contains(' ' . $state['tokens'][substr((string)$_SERVER['HTTP_AUTHORIZATION'], 7)] . ' ', ' patient/QuestionnaireResponse.cu ')) {
            $json(403, ['resourceType' => 'OperationOutcome', 'issue' => [['code' => 'forbidden', 'diagnostics' => 'no write scope']]]);
        }
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $id = 'r' . (count($state['responses']) + 1);
        $state['responses'][$id] = ['resource' => ['id' => $id] + $body, 'status' => 'pending'];
        $save();
        header('Location: ' . $base . '/fhir/QuestionnaireResponse/' . $id);
        $json(201, $held(['id' => $id] + $body));
    case $path === '/fhir/QuestionnaireResponse' && $method === 'GET':
        $live = array_filter($state['responses'], static fn(array $r): bool => $r['status'] === 'pending');
        $json(200, $bundle(array_map(static fn(array $r): array => $held($r['resource']), $live)));
    case preg_match('~^/fhir/QuestionnaireResponse/(\w+)$~', $path, $m) === 1:
        $stored = $state['responses'][$m[1]] ?? null;
        if ($stored === null) {
            $json(404, ['resourceType' => 'OperationOutcome', 'issue' => [['code' => 'not-found']]]);
        }
        if ($stored['status'] !== 'pending') {
            $json(410, ['resourceType' => 'OperationOutcome', 'issue' => [['code' => 'deleted']]]);
        }
        if ($method === 'PUT') {
            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            if (($body['status'] ?? '') === 'entered-in-error') {
                $state['responses'][$m[1]]['status'] = 'cancelled';
                $json(200, $body);
            }
            $state['responses'][$m[1]]['resource'] = $body;
            $json(200, $held($body));
        }
        $json(200, $held($stored['resource']));
}
$json(404, ['resourceType' => 'OperationOutcome', 'issue' => [['code' => 'not-found', 'diagnostics' => $path]]]);
