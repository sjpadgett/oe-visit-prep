<?php

/**
 * End to end against tests/mock-ehr.php: register, portal launch, sign-in, review, send (held for
 * review), withdraw, and a practice that isn't accepting app writes.   php tests/e2e.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/visitprep-e2e-' . getmypid();
mkdir($work, 0700, true);
$app = 'http://127.0.0.1:18190';
$ehr = 'http://127.0.0.1:18191';
$ehrNoWrites = 'http://127.0.0.1:18192';
file_put_contents("{$work}/config.php", '<?php return ' . var_export([
    'app_url' => $app, 'verify_tls' => false, 'clients_file' => "{$work}/clients.json",
], true) . ';');

$servers = [];
$start = static function (string $address, string $docroot, ?string $router, array $env) use (&$servers, $work): void {
    $cmd = [PHP_BINARY, '-S', $address, '-t', $docroot];
    if ($router !== null) {
        $cmd[] = $router;
    }
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $servers[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', "{$work}/server.log", 'a']], $pipes, null, array_merge(getenv(), $env));
};
$start('127.0.0.1:18190', "{$root}/public", null, ['VISITPREP_CONFIG' => "{$work}/config.php"]);
$start('127.0.0.1:18191', "{$root}/tests", "{$root}/tests/mock-ehr.php", ['MOCK_STATE_FILE' => "{$work}/ehr.json"]);
$start('127.0.0.1:18192', "{$root}/tests", "{$root}/tests/mock-ehr.php", ['MOCK_STATE_FILE' => "{$work}/ehr2.json", 'MOCK_WRITES' => 'off']);
register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $server) {
        proc_terminate($server);
    }
});
usleep(700000);

$count = 0;
$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $count, $failures;
    $count++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'ok    ' : 'FAIL  ') . $name . ($ok || $detail === '' ? '' : "\n      " . substr($detail, 0, 600)) . "\n";
}

/** @return array{status: int, body: string, url: string} */
function http(string $method, string $url, string $jar, ?array $form = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar] + ($form !== null ? [CURLOPT_POSTFIELDS => http_build_query($form)] : []));
    $body = (string)curl_exec($ch);
    $result = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body, 'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
    curl_close($ch);
    return $result;
}

$cli = static function (string $script, string $args) use ($root, $work): array {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/bin/{$script}") . " {$args} 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
};
putenv("VISITPREP_CONFIG={$work}/config.php");
$ehrState = static fn(string $file = 'ehr.json'): array => json_decode((string)file_get_contents("{$work}/{$file}"), true) ?: [];

// --- registration ----------------------------------------------------------------------------
[$code, $out] = $cli('register.php', '--iss=' . escapeshellarg("{$ehr}/fhir"));
$client = (json_decode((string)file_get_contents("{$work}/clients.json"), true) ?: [])["{$ehr}/fhir"] ?? [];
check('register saves a confidential client', $code === 0 && isset($client['client_id'], $client['client_secret']), $out);
$payload = end($ehrState()['clients'])['body'] ?? [];
check('registered as private with the launch URI', ($payload['application_type'] ?? '') === 'private' && ($payload['initiate_login_uri'] ?? '') === "{$app}/launch.php");
check('scopes: launch and patient scopes only, no launch/patient or user/', str_contains(' ' . ($payload['scope'] ?? '') . ' ', ' launch ')
    && !str_contains($payload['scope'] ?? '', 'launch/patient') && !str_contains($payload['scope'] ?? '', 'user/')
    && str_contains($payload['scope'] ?? '', 'patient/QuestionnaireResponse.cu'), (string)($payload['scope'] ?? ''));

// --- launch ----------------------------------------------------------------------------------
$jar = "{$work}/cookies.txt";
$r = http('GET', "{$app}/launch.php", $jar);
check('launch without iss and launch says to use the portal', $r['status'] === 400 && str_contains($r['body'], 'patient portal'));
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => 'http://127.0.0.1:1/fhir', 'launch' => 'x']), $jar);
check('unknown issuer refused before any redirect', $r['status'] === 400 && str_contains($r['body'], 'not registered'));
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => "{$ehr}/fhir", 'launch' => 'Lportal', 'aud' => "{$ehr}/fhir"]), $jar);
check('portal launch lands on the review page', $r['status'] === 200 && str_contains($r['url'], '/index.php') && str_contains($r['body'], 'Ada Lovelace'), $r['url'] . ' ' . strip_tags($r['body']));
check('authorize got the launch, aud and PKCE', (bool)preg_grep('~^GET /oauth/authorize\?(?=.*launch=Lportal)(?=.*aud=)(?=.*code_challenge_method=S256)~', $ehrState()['log']));
check('active medicines listed, stopped one left out', str_contains($r['body'], 'Lisinopril 10 mg tablet') && str_contains($r['body'], 'One tablet daily')
    && str_contains($r['body'], 'Metformin 500 mg') && !str_contains($r['body'], 'Old antibiotic'));
check('allergy, condition and next visit shown', str_contains($r['body'], 'Penicillin') && str_contains($r['body'], 'Hives')
    && str_contains($r['body'], 'Hypertension') && str_contains($r['body'], 'Annual exam'));
check('send button offered', str_contains($r['body'], 'Send to my care team'));
check('strict CSP, no inline script', !preg_match('~<script(?![^>]*src=)~', $r['body']));
$csrf = preg_match('~name="csrf" value="([0-9a-f]+)"~', $r['body'], $m) === 1 ? $m[1] : '';

// --- send ------------------------------------------------------------------------------------
$r = http('POST', "{$app}/index.php", $jar, ['csrf' => 'wrong', 'action' => 'send']);
check('a wrong CSRF token is refused', $r['status'] === 400);
$r = http('POST', "{$app}/index.php", $jar, ['csrf' => $csrf, 'action' => 'send', 'medications' => ['m1' => ['status' => 'taking']]]);
check('unanswered records are named', str_contains($r['body'], 'Please answer for: Metformin 500 mg, Penicillin.'), strip_tags($r['body']));
check('nothing sent while answers are missing', ($ehrState()['responses'] ?? []) === []);
$r = http('POST', "{$app}/index.php", $jar, [
    'csrf' => $csrf, 'action' => 'send',
    'medications' => ['m1' => ['status' => 'different', 'note' => 'Half a tablet'], 'm2' => ['status' => 'taking'], 'evil' => ['status' => 'stopped']],
    'allergies' => ['a1' => ['status' => 'correct']],
    'medications-new' => 'Vitamin D 1000 IU',
    'questions' => 'Can I stop metformin?',
]);
check('send is held for review', str_contains($r['body'], 'Your care team will review it'), strip_tags($r['body']));
$sent = array_values($ehrState()['responses'])[0]['resource'] ?? [];
$items = $sent['item'] ?? [];
$byLink = static function (array $items, string $linkId): array {
    return array_values(array_filter($items, static fn(array $i): bool => ($i['linkId'] ?? '') === $linkId));
};
$meds = $byLink($items, 'medications');
check('response answers the Visit Prep Questionnaire for the token patient', ($sent['questionnaire'] ?? '') === 'Questionnaire/q-visit'
    && ($sent['subject']['reference'] ?? '') === 'Patient/p1' && ($sent['status'] ?? '') === 'completed');
check('one group per medicine on file, a forged id ignored', count($meds) === 2 && !str_contains(json_encode($sent), 'evil'), json_encode($meds));
check('medicine group carries name, record, choice and note', ($byLink($meds[0]['item'] ?? [], 'medications.reference')[0]['answer'][0]['valueString'] ?? '') === 'MedicationRequest/m1'
    && ($byLink($meds[0]['item'] ?? [], 'medications.status')[0]['answer'][0]['valueCoding']['code'] ?? '') === 'different'
    && ($byLink($meds[0]['item'] ?? [], 'medications.note')[0]['answer'][0]['valueString'] ?? '') === 'Half a tablet');
check('allergy, new medicines, questions and visit included', count($byLink($items, 'allergies')) === 1
    && ($byLink($items, 'medications-new')[0]['answer'][0]['valueString'] ?? '') === 'Vitamin D 1000 IU'
    && ($byLink($items, 'questions')[0]['answer'][0]['valueString'] ?? '') === 'Can I stop metformin?'
    && str_contains($byLink($items, 'visit')[0]['answer'][0]['valueString'] ?? '', 'Annual exam'));
check('page lists it as waiting for review with Withdraw', str_contains($r['body'], 'waiting for review') && str_contains($r['body'], '>Withdraw<'));

// --- withdraw --------------------------------------------------------------------------------
$r = http('POST', "{$app}/index.php", $jar, ['csrf' => $csrf, 'action' => 'withdraw', 'id' => 'not-mine']);
check('withdrawing an id that is not a held submission is refused', str_contains($r['body'], 'can no longer be withdrawn'));
$r = http('POST', "{$app}/index.php", $jar, ['csrf' => $csrf, 'action' => 'withdraw', 'id' => 'r1']);
check('withdraw cancels the held submission', str_contains($r['body'], 'Withdrawn.') && ($ehrState()['responses']['r1']['status'] ?? '') === 'cancelled', strip_tags($r['body']));
check('withdrawn one is gone from the page', !str_contains($r['body'], 'waiting for review'));

// --- a practice not accepting app writes -----------------------------------------------------
[$code, $out] = $cli('register.php', '--iss=' . escapeshellarg("{$ehrNoWrites}/fhir"));
check('register without write scopes says patients cannot send yet', $code === 0 && str_contains($out, "Patients can't send yet"), $out);
$jar2 = "{$work}/cookies2.txt";
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => "{$ehrNoWrites}/fhir", 'launch' => 'L2']), $jar2);
check('without write scopes: look but not send, no send button', str_contains($r['body'], 'look but not send') && !str_contains($r['body'], 'Send to my care team'), strip_tags($r['body']));

// --- sign-in state ---------------------------------------------------------------------------
$r = http('GET', "{$app}/index.php", "{$work}/fresh.txt");
check('the page without a sign-in asks to open it again', $r['status'] === 401);
$log = is_file("{$work}/server.log") ? (string)file_get_contents("{$work}/server.log") : '';
check('no PHP warnings or errors', !preg_match('~PHP (Warning|Notice|Fatal|Deprecated)~', $log), $log);

echo ($failures === 0 ? 'OK' : 'FAILED') . " ({$count} checks, {$failures} failures)\n";
exit($failures === 0 ? 0 : 1);
