<?php

/**
 * Adds the Visit Prep Questionnaire to an OpenEMR, once. Needs a staff access token with
 * user/Questionnaire.write (for example from the API Explorer's Confidential client).
 *
 *   php bin/seed-questionnaire.php --iss=https://ehr.example/apis/default/fhir --token=<staff access token>
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
use OpenEMR\VisitPrep\Fhir;
use OpenEMR\VisitPrep\Http;
use OpenEMR\VisitPrep\Review;

$options = getopt('', ['iss:', 'token:']);
if (!is_string($options['iss'] ?? null) || !is_string($options['token'] ?? null)) {
    fwrite(STDERR, "Usage: php bin/seed-questionnaire.php --iss=<FHIR base URL> --token=<staff access token>\n");
    exit(2);
}
$fhir = new Fhir(new Http(Config::verifyTls()), Config::normalizeIssuer($options['iss']), $options['token']);

foreach ($fhir->search('Questionnaire?_count=100') ?? [] as $questionnaire) {
    if (Review::isVisitPrep($questionnaire)) {
        fwrite(STDOUT, "✓ Already there: Questionnaire/{$questionnaire['id']}\n");
        exit(0);
    }
}
$definition = json_decode((string)file_get_contents(dirname(__DIR__) . '/questionnaire/visit-prep.questionnaire.json'), true, 512, JSON_THROW_ON_ERROR);
$r = $fhir->call('POST', 'Questionnaire', $definition);
if ($r['status'] !== 201 && $r['status'] !== 200) {
    fwrite(STDERR, "✗ HTTP {$r['status']} " . Fhir::problem($r['json'])
        . ($r['status'] === 401 || $r['status'] === 403 ? ' (the token needs user/Questionnaire.write and an admin user)' : '') . "\n");
    exit(1);
}
fwrite(STDOUT, "✓ Added the Visit Prep Questionnaire" . ($r['location'] !== '' ? ": {$r['location']}" : '') . "\n");
