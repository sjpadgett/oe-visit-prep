<?php

/**
 * Configuration (config.php) and the registered clients (var/clients.json).
 *
 * The client list is also the issuer allowlist: the app only launches against a FHIR server it
 * is registered with, so a forged ?iss= never receives a code or a token.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\VisitPrep;

use RuntimeException;

final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$config === null) {
            $override = getenv('VISITPREP_CONFIG');
            $file = is_string($override) && $override !== '' ? $override : dirname(__DIR__) . '/config.php';
            if (!is_file($file)) {
                throw new RuntimeException('Missing config.php: copy config.sample.php to config.php and set app_url.');
            }
            $loaded = require $file;
            if (!is_array($loaded) || !is_string($loaded['app_url'] ?? null) || $loaded['app_url'] === '') {
                throw new RuntimeException('config.php must return an array with app_url.');
            }
            self::$config = $loaded + [
                'app_name' => 'Visit Prep',
                'verify_tls' => true,
                'clients_file' => dirname(__DIR__) . '/var/clients.json',
                'contact' => 'admin@example.org',
            ];
            self::$config['app_url'] = rtrim((string)self::$config['app_url'], '/');
        }

        return self::$config;
    }

    public static function string(string $key): string
    {
        $value = self::all()[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public static function verifyTls(): bool
    {
        return (bool)(self::all()['verify_tls'] ?? true);
    }

    public static function launchUri(): string
    {
        return self::string('app_url') . '/launch.php';
    }

    public static function redirectUri(): string
    {
        return self::string('app_url') . '/callback.php';
    }

    public static function normalizeIssuer(string $issuer): string
    {
        return rtrim(trim($issuer), '/');
    }

    /** @return array<string, array<string, mixed>> issuer => client */
    public static function clients(): array
    {
        $file = self::string('clients_file');
        $decoded = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $clients = [];
        foreach (is_array($decoded) ? $decoded : [] as $issuer => $client) {
            if (is_string($issuer) && is_array($client) && is_string($client['client_id'] ?? null)) {
                $clients[self::normalizeIssuer($issuer)] = $client;
            }
        }
        return $clients;
    }

    /** @return array<string, mixed>|null */
    public static function clientFor(string $issuer): ?array
    {
        return self::clients()[self::normalizeIssuer($issuer)] ?? null;
    }

    /** @param array<string, mixed> $client */
    public static function saveClient(string $issuer, array $client): void
    {
        $file = self::string('clients_file');
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $clients = self::clients();
        $clients[self::normalizeIssuer($issuer)] = $client;
        if (file_put_contents($file, json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
        @chmod($file, 0600);
    }
}
