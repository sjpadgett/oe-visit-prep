<?php

/**
 * The visit prep review: what the patient's record says (medicines, allergies, conditions, the
 * next visit), the patient's earlier submissions, and the QuestionnaireResponse built from the
 * form.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\VisitPrep;

use InvalidArgumentException;

final class Review
{
    /** The Visit Prep Questionnaire's canonical. OpenEMR publishes its own url, so it is also an identifier. */
    public const CANONICAL = 'http://open-emr.org/fhir/Questionnaire/visit-prep';
    public const TITLE = 'Visit Prep: medicines and allergies';
    public const ANSWERS = 'http://open-emr.org/fhir/CodeSystem/visit-prep';

    /** @var array<string, string> */
    public const MEDICATION_CHOICES = ['taking' => 'Yes, as prescribed', 'different' => 'Yes, but differently', 'stopped' => 'No, I stopped'];
    /** @var array<string, string> */
    public const ALLERGY_CHOICES = ['correct' => 'Yes', 'incorrect' => 'No, this is wrong'];

    public function __construct(private readonly Fhir $fhir, private readonly string $patientId)
    {
    }

    /** @return array<string, mixed>|null */
    public function patient(): ?array
    {
        return $this->fhir->read('Patient/' . rawurlencode($this->patientId));
    }

    public static function patientName(?array $patient): string
    {
        $name = is_array($patient['name'][0] ?? null) ? $patient['name'][0] : [];
        $given = is_array($name['given'] ?? null) ? implode(' ', array_filter($name['given'], 'is_string')) : '';
        return trim($given . ' ' . (is_string($name['family'] ?? null) ? $name['family'] : '')) ?: 'Patient';
    }

    /**
     * Active medicines: id, name and the dosage text.
     *
     * @return list<array{id: string, name: string, detail: string}>|null
     */
    public function medications(): ?array
    {
        $found = $this->fhir->search('MedicationRequest?patient=' . rawurlencode($this->patientId) . '&_count=100');
        if ($found === null) {
            return null;
        }
        $medications = [];
        foreach ($found as $request) {
            $status = $request['status'] ?? 'active';
            if (!is_string($request['id'] ?? null) || !in_array($status, ['active', 'on-hold', 'unknown'], true)) {
                continue;
            }
            $dosage = is_array($request['dosageInstruction'][0] ?? null) ? $request['dosageInstruction'][0] : [];
            $medications[] = [
                'id' => $request['id'],
                'name' => Fhir::label($request['medicationCodeableConcept'] ?? null) ?: 'Unnamed medicine',
                'detail' => is_string($dosage['text'] ?? null) ? $dosage['text'] : '',
            ];
        }
        return $medications;
    }

    /**
     * Allergies that are not resolved or refuted.
     *
     * @return list<array{id: string, name: string, detail: string}>|null
     */
    public function allergies(): ?array
    {
        $found = $this->fhir->search('AllergyIntolerance?patient=' . rawurlencode($this->patientId) . '&_count=100');
        if ($found === null) {
            return null;
        }
        $allergies = [];
        foreach ($found as $allergy) {
            $clinical = $allergy['clinicalStatus']['coding'][0]['code'] ?? 'active';
            $verification = $allergy['verificationStatus']['coding'][0]['code'] ?? '';
            if (!is_string($allergy['id'] ?? null) || in_array($clinical, ['resolved', 'inactive'], true) || in_array($verification, ['refuted', 'entered-in-error'], true)) {
                continue;
            }
            $reaction = is_array($allergy['reaction'][0] ?? null) ? $allergy['reaction'][0] : [];
            $allergies[] = [
                'id' => $allergy['id'],
                'name' => Fhir::label($allergy['code'] ?? null) ?: 'Unnamed allergy',
                'detail' => Fhir::label($reaction['manifestation'][0] ?? null),
            ];
        }
        return $allergies;
    }

    /** @return list<string>|null active problem names, shown for context */
    public function conditions(): ?array
    {
        $found = $this->fhir->search('Condition?patient=' . rawurlencode($this->patientId) . '&_count=100');
        if ($found === null) {
            return null;
        }
        $names = [];
        foreach ($found as $condition) {
            $clinical = $condition['clinicalStatus']['coding'][0]['code'] ?? 'active';
            if (in_array($clinical, ['active', 'recurrence', 'relapse'], true)) {
                $names[] = Fhir::label($condition['code'] ?? null) ?: 'Unnamed condition';
            }
        }
        return array_values(array_unique($names));
    }

    /** The next booked visit's start and description, or null when there is none or no access. */
    public function nextVisit(): ?string
    {
        $found = $this->fhir->search('Appointment?patient=' . rawurlencode($this->patientId) . '&date=ge' . gmdate('Y-m-d') . '&_count=50');
        $next = null;
        foreach ($found ?? [] as $appointment) {
            $start = is_string($appointment['start'] ?? null) ? $appointment['start'] : '';
            if ($start === '' || in_array($appointment['status'] ?? '', ['cancelled', 'noshow', 'entered-in-error'], true) || strtotime($start) < time() - 3600) {
                continue;
            }
            if ($next === null || strcmp($start, $next['start']) < 0) {
                $next = ['start' => $start, 'description' => (string)($appointment['description'] ?? '')];
            }
        }
        if ($next === null) {
            return null;
        }
        $when = date('l, F j, Y \a\t g:i A', (int)strtotime($next['start']));
        return trim($when . ($next['description'] !== '' ? ' — ' . $next['description'] : ''));
    }

    /**
     * The practice's Visit Prep Questionnaire: found by its canonical as url or identifier, and
     * its title. Null when the practice hasn't added it.
     *
     * @return array<string, mixed>|null
     */
    public function questionnaire(): ?array
    {
        foreach ($this->fhir->search('Questionnaire?_count=100') ?? [] as $questionnaire) {
            if (self::isVisitPrep($questionnaire)) {
                return $questionnaire;
            }
        }
        return null;
    }

    public static function isVisitPrep(array $questionnaire): bool
    {
        if (!is_string($questionnaire['id'] ?? null) || ($questionnaire['title'] ?? null) !== self::TITLE) {
            return false;
        }
        if (($questionnaire['url'] ?? null) === self::CANONICAL) {
            return true;
        }
        foreach (is_array($questionnaire['identifier'] ?? null) ? $questionnaire['identifier'] : [] as $identifier) {
            if (is_array($identifier) && ($identifier['value'] ?? null) === self::CANONICAL) {
                return true;
            }
        }
        return false;
    }

    /**
     * This patient's earlier Visit Prep submissions, newest first.
     *
     * @return list<array{id: string, authored: string, held: bool, status: string}>
     */
    public function submissions(string $questionnaireId): array
    {
        $mine = [];
        foreach ($this->fhir->search('QuestionnaireResponse?patient=' . rawurlencode($this->patientId) . '&_count=100') ?? [] as $response) {
            $answers = is_string($response['questionnaire'] ?? null) ? $response['questionnaire'] : '';
            if (!is_string($response['id'] ?? null) || preg_match('~(^|/)Questionnaire/' . preg_quote($questionnaireId, '~') . '(\||$)~', $answers) !== 1) {
                continue;
            }
            $mine[] = [
                'id' => $response['id'],
                'authored' => (string)($response['authored'] ?? ''),
                'held' => Fhir::isHeld($response),
                'status' => (string)($response['status'] ?? ''),
            ];
        }
        usort($mine, static fn(array $a, array $b): int => strcmp($b['authored'], $a['authored']));
        return $mine;
    }

    /**
     * The QuestionnaireResponse for the submitted form. The app, not the browser, decides the
     * patient, the Questionnaire and which records are reviewed: the form only says, for each
     * record shown, what the patient answered.
     *
     * @param array<string, mixed> $form the POST body
     * @param list<array{id: string, name: string, detail: string}> $medications
     * @param list<array{id: string, name: string, detail: string}> $allergies
     * @return array<string, mixed>
     * @throws InvalidArgumentException naming what is missing
     */
    public function response(string $questionnaireId, array $form, array $medications, array $allergies, ?string $visit): array
    {
        $items = [];
        if ($visit !== null) {
            $items[] = ['linkId' => 'visit', 'answer' => [['valueString' => $visit]]];
        }
        $missing = [];
        foreach (['medications' => [$medications, self::MEDICATION_CHOICES], 'allergies' => [$allergies, self::ALLERGY_CHOICES]] as $group => [$records, $choices]) {
            $answers = is_array($form[$group] ?? null) ? $form[$group] : [];
            $resourceType = $group === 'medications' ? 'MedicationRequest' : 'AllergyIntolerance';
            foreach ($records as $record) {
                $answer = is_array($answers[$record['id']] ?? null) ? $answers[$record['id']] : [];
                $choice = is_string($answer['status'] ?? null) ? $answer['status'] : '';
                if (!isset($choices[$choice])) {
                    $missing[] = $record['name'];
                    continue;
                }
                $note = self::text($answer['note'] ?? null, 500);
                $items[] = ['linkId' => $group, 'item' => array_values(array_filter([
                    ['linkId' => "{$group}.name", 'answer' => [['valueString' => $record['name']]]],
                    ['linkId' => "{$group}.reference", 'answer' => [['valueString' => "{$resourceType}/{$record['id']}"]]],
                    ['linkId' => "{$group}.status", 'answer' => [['valueCoding' => ['system' => self::ANSWERS, 'code' => $choice, 'display' => $choices[$choice]]]]],
                    $note !== '' ? ['linkId' => "{$group}.note", 'answer' => [['valueString' => $note]]] : null,
                ]))];
            }
        }
        if ($missing !== []) {
            throw new InvalidArgumentException('Please answer for: ' . implode(', ', $missing) . '.');
        }
        foreach (['medications-new', 'allergies-new', 'questions'] as $linkId) {
            $text = self::text($form[$linkId] ?? null, 2000);
            if ($text !== '') {
                $items[] = ['linkId' => $linkId, 'answer' => [['valueString' => $text]]];
            }
        }

        return [
            'resourceType' => 'QuestionnaireResponse',
            'status' => 'completed',
            'questionnaire' => 'Questionnaire/' . $questionnaireId,
            'subject' => ['reference' => 'Patient/' . $this->patientId],
            'authored' => gmdate('Y-m-d\TH:i:s\Z'),
            'item' => $items,
        ];
    }

    private static function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }
}
