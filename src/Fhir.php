<?php

/**
 * FHIR calls with the launch's access token.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\VisitPrep;

final class Fhir
{
    public function __construct(private readonly Http $http, private readonly string $base, private readonly string $token)
    {
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, json: array<string, mixed>|null, location: string}
     */
    public function call(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/fhir+json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/fhir+json';
        }
        $r = $this->http->request($method, rtrim($this->base, '/') . '/' . ltrim($path, '/'), $headers,
            $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null);
        $json = json_decode($r['body'], true);

        return ['status' => $r['status'], 'json' => is_array($json) ? $json : null, 'location' => $r['location']];
    }

    /** @return array<string, mixed>|null */
    public function read(string $path): ?array
    {
        $r = $this->call('GET', $path);
        return $r['status'] === 200 ? $r['json'] : null;
    }

    /**
     * The resources of a search, or null when the search was refused (no scope for it, or the
     * server failed), so a page can tell "none" from "couldn't look".
     *
     * @return list<array<string, mixed>>|null
     */
    public function search(string $path): ?array
    {
        $r = $this->call('GET', $path);
        if ($r['status'] !== 200 || $r['json'] === null) {
            return null;
        }
        $resources = [];
        foreach (is_array($r['json']['entry'] ?? null) ? $r['json']['entry'] : [] as $entry) {
            if (is_array($entry['resource'] ?? null)) {
                $resources[] = $entry['resource'];
            }
        }
        return $resources;
    }

    /** The first diagnostics or text of an OperationOutcome. */
    public static function problem(?array $json): string
    {
        $issue = is_array($json['issue'][0] ?? null) ? $json['issue'][0] : [];
        return (string)($issue['diagnostics'] ?? $issue['details']['text'] ?? $issue['code'] ?? '');
    }

    /** Whether OpenEMR is holding this patient app write for staff review. */
    public static function isHeld(?array $resource): bool
    {
        foreach (is_array($resource['meta']['tag'] ?? null) ? $resource['meta']['tag'] : [] as $tag) {
            if (is_array($tag) && ($tag['code'] ?? null) === 'held-for-review') {
                return true;
            }
        }
        return false;
    }

    /** A CodeableConcept's text, else its first coding's display or code. */
    public static function label(mixed $concept): string
    {
        if (!is_array($concept)) {
            return '';
        }
        if (is_string($concept['text'] ?? null) && $concept['text'] !== '') {
            return $concept['text'];
        }
        $coding = is_array($concept['coding'][0] ?? null) ? $concept['coding'][0] : [];
        return (string)($coding['display'] ?? $coding['code'] ?? '');
    }
}
