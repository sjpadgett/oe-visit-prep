<?php

/**
 * SMART App Launch 2.x client pieces: discovery, PKCE, the authorize URL and the token exchange.
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

final class Smart
{
    /**
     * The scopes a portal launch asks for. `launch` (not `launch/patient`) takes the patient from
     * the portal's launch. OpenEMR publishes most patient reads as SMART v2 `.rs` and Appointment
     * only as v1 `.read`; QuestionnaireResponse writes exist only as v2 `.cu` and only while the
     * practice has Patient App FHIR Writes on. bin/register.php keeps what the server publishes.
     */
    public const SCOPES = 'openid fhirUser launch patient/Patient.rs patient/MedicationRequest.rs '
        . 'patient/AllergyIntolerance.rs patient/Condition.rs patient/Appointment.read '
        . 'patient/Questionnaire.rs patient/QuestionnaireResponse.rs patient/QuestionnaireResponse.cu';

    /** The ones the app can't do without. */
    public const REQUIRED = ['launch', 'patient/Patient.rs'];

    public function __construct(private readonly Http $http)
    {
    }

    /** @return array<string, mixed> the server's .well-known/smart-configuration */
    public function discover(string $issuer): array
    {
        $r = $this->http->request('GET', Config::normalizeIssuer($issuer) . '/.well-known/smart-configuration', ['Accept' => 'application/json']);
        $config = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($config) || !is_string($config['authorization_endpoint'] ?? null) || !is_string($config['token_endpoint'] ?? null)) {
            throw new RuntimeException("SMART discovery at {$issuer} failed (HTTP {$r['status']}).");
        }
        return $config;
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function newVerifier(): string
    {
        return self::base64Url(random_bytes(48));
    }

    public static function challengeFor(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /** @param array<string, mixed> $configuration */
    public static function authorizeUrl(array $configuration, string $clientId, string $issuer, string $scope, string $state, string $verifier, string $launch): string
    {
        $endpoint = (string)$configuration['authorization_endpoint'];
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => Config::redirectUri(),
            'scope' => $scope,
            'state' => $state,
            'aud' => $issuer,
            'launch' => $launch,
            'code_challenge' => self::challengeFor($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $client
     * @return array<string, mixed> the token response
     */
    public function exchangeCode(array $configuration, array $client, string $code, string $verifier): array
    {
        $r = $this->http->request('POST', (string)$configuration['token_endpoint'], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query(array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => Config::redirectUri(),
            'client_id' => (string)$client['client_id'],
            'client_secret' => is_string($client['client_secret'] ?? null) ? $client['client_secret'] : null,
            'code_verifier' => $verifier,
        ], static fn($value) => $value !== null)));
        $token = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($token) || !is_string($token['access_token'] ?? null)) {
            $reason = is_array($token) ? (string)($token['error_description'] ?? $token['message'] ?? $token['error'] ?? '') : '';
            throw new RuntimeException("Token request failed (HTTP {$r['status']})" . ($reason !== '' ? ": {$reason}" : '.'));
        }
        return $token;
    }
}
