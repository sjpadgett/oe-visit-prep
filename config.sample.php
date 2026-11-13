<?php

/**
 * Copy to config.php and edit. config.php is git-ignored.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

return [
    // Public URL of the public/ directory, no trailing slash. The launch URI is
    // <app_url>/launch.php and the redirect URI <app_url>/callback.php.
    'app_url' => 'https://localhost/oe-visit-prep/public',
    // Check the EHR's TLS certificate. Set false only for a development OpenEMR with a
    // self-signed certificate.
    'verify_tls' => true,
    // The contact sent when registering.
    'contact' => 'admin@example.org',
];
