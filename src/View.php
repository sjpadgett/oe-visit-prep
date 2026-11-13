<?php

/**
 * Page chrome: header, footer and error pages. No inline script or style, so the strict
 * Content-Security-Policy holds.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\VisitPrep;

final class View
{
    public static function header(string $title): void
    {
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . h($title) . ' · ' . h(Config::string('app_name')) . '</title>'
            . '<link rel="stylesheet" href="assets/app.css"></head><body><main class="page">';
    }

    public static function footer(): void
    {
        echo '<footer class="muted small">Visit Prep is an example SMART on FHIR app for OpenEMR\'s patient portal. '
            . 'What you send is reviewed by your care team before it is added to your record.</footer>'
            . '</main></body></html>';
    }

    public static function error(int $status, string $title, string $message): never
    {
        http_response_code($status);
        self::header($title);
        echo '<h1>' . h($title) . '</h1><p class="notice warn">' . h($message) . '</p>';
        self::footer();
        exit;
    }
}
