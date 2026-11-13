<?php

/**
 * Bootstrap: autoload, configuration, session and the HTML helpers every page uses.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenEMR\\VisitPrep\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/** Escapes text for HTML. */
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Starts the session with safe cookie settings and sends the page's security headers. The
 * access token lives only here, server side; the browser never sees it.
 */
function start_page(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('visitprep');
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}
