<?php

/**
 * The Visit Prep page: the patient reviews the medicines and allergies on file, adds what is
 * missing and asks questions; the app sends it as one QuestionnaireResponse, which OpenEMR holds
 * for staff review. A held submission can be withdrawn until staff decide.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use OpenEMR\VisitPrep\Config;
use OpenEMR\VisitPrep\Fhir;
use OpenEMR\VisitPrep\Http;
use OpenEMR\VisitPrep\Review;
use OpenEMR\VisitPrep\View;

start_page();

$visit = $_SESSION['visit'] ?? null;
if (!is_array($visit) || time() >= (int)$visit['expires_at']) {
    unset($_SESSION['visit']);
    View::error(401, 'Please open Visit Prep again', 'Your sign-in has ended. Open Visit Prep from Health Apps in your patient portal.');
}

$fhir = new Fhir(new Http(Config::verifyTls()), (string)$visit['issuer'], (string)$visit['token']);
$review = new Review($fhir, (string)$visit['patient']);
// Sending needs create on QuestionnaireResponse: OpenEMR grants patient/QuestionnaireResponse.cu only
// while the practice accepts information from patient apps.
$canSend = preg_match('~(^|\s)patient/QuestionnaireResponse\.c\w*(\s|$)~', (string)$visit['scope']) === 1;

$questionnaire = $review->questionnaire();
$medications = $review->medications();
$allergies = $review->allergies();
$nextVisit = $review->nextVisit();
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string)$visit['csrf'], is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '')) {
        View::error(400, 'Please try again', 'This page was open too long. Reload it and send again.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'send' && $questionnaire !== null && $canSend) {
        try {
            $response = $review->response((string)$questionnaire['id'], $_POST, $medications ?? [], $allergies ?? [], $nextVisit);
            $sent = $fhir->call('POST', 'QuestionnaireResponse', $response);
            $notice = $sent['status'] === 201
                ? ['ok', Fhir::isHeld($sent['json'])
                    ? 'Sent. Your care team will review it before your visit. You can withdraw it until they do.'
                    : 'Sent.']
                : ['warn', 'It was not sent (HTTP ' . $sent['status'] . '). ' . Fhir::problem($sent['json'])];
        } catch (InvalidArgumentException $exception) {
            $notice = ['warn', $exception->getMessage()];
        }
    } elseif ($action === 'withdraw' && $questionnaire !== null) {
        $id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
        // Only a submission of this patient that is still held can be withdrawn.
        $held = array_filter($review->submissions((string)$questionnaire['id']), static fn(array $s): bool => $s['id'] === $id && $s['held']);
        $current = $held !== [] ? $fhir->read('QuestionnaireResponse/' . rawurlencode($id)) : null;
        if ($current === null) {
            $notice = ['warn', 'That submission can no longer be withdrawn.'];
        } else {
            unset($current['meta'], $current['text']);
            $current['status'] = 'entered-in-error';
            $put = $fhir->call('PUT', 'QuestionnaireResponse/' . rawurlencode($id), $current);
            $notice = $put['status'] === 200 ? ['ok', 'Withdrawn.'] : ['warn', 'It was not withdrawn (HTTP ' . $put['status'] . '). ' . Fhir::problem($put['json'])];
        }
    }
}

$patientName = Review::patientName($review->patient());
$conditions = $review->conditions();
$submissions = $questionnaire !== null ? $review->submissions((string)$questionnaire['id']) : [];

View::header('Get ready for your visit');
?>
<header class="bar">
    <div><strong><?= h($patientName) ?></strong><div class="muted small">Opened from your patient portal</div></div>
</header>

<h1>Get ready for your visit</h1>
<p class="lead"><?= $nextVisit !== null ? 'Your next visit: <strong>' . h($nextVisit) . '</strong>.' : 'Check your medicines and allergies so your care team has them right.' ?></p>

<?php if ($notice !== null) : ?>
    <p class="notice <?= h($notice[0]) ?>"><?= h($notice[1]) ?></p>
<?php endif; ?>

<?php if ($questionnaire === null) : ?>
    <p class="notice warn">Your practice hasn't set up Visit Prep yet, so nothing can be sent. You can still look over your record below.</p>
<?php elseif (!$canSend) : ?>
    <p class="notice warn">Your practice isn't accepting information from apps right now, so you can look but not send.</p>
<?php endif; ?>

<form method="post" action="index.php">
    <input type="hidden" name="csrf" value="<?= h((string)$visit['csrf']) ?>">
    <input type="hidden" name="action" value="send">

    <section class="card">
        <h2>Your medicines</h2>
        <?php if ($medications === null) : ?>
            <p class="muted">Your medicines could not be read.</p>
        <?php elseif ($medications === []) : ?>
            <p class="muted">No medicines are on file.</p>
        <?php else : ?>
            <?php foreach ($medications as $medication) : ?>
                <fieldset class="row">
                    <legend><?= h($medication['name']) ?><?php if ($medication['detail'] !== '') : ?> <span class="muted">— <?= h($medication['detail']) ?></span><?php endif; ?></legend>
                    <?php foreach (Review::MEDICATION_CHOICES as $code => $label) : ?>
                        <label class="choice"><input type="radio" required name="medications[<?= h($medication['id']) ?>][status]" value="<?= h($code) ?>"> <?= h($label) ?></label>
                    <?php endforeach; ?>
                    <input class="note" type="text" maxlength="500" name="medications[<?= h($medication['id']) ?>][note]" placeholder="How are you taking it? (optional)">
                </fieldset>
            <?php endforeach; ?>
        <?php endif; ?>
        <label class="block">Medicines you take that are not on the list, including vitamins and supplements
            <textarea name="medications-new" rows="3" maxlength="2000"></textarea></label>
    </section>

    <section class="card">
        <h2>Your allergies</h2>
        <?php if ($allergies === null) : ?>
            <p class="muted">Your allergies could not be read.</p>
        <?php elseif ($allergies === []) : ?>
            <p class="muted">No allergies are on file.</p>
        <?php else : ?>
            <?php foreach ($allergies as $allergy) : ?>
                <fieldset class="row">
                    <legend><?= h($allergy['name']) ?><?php if ($allergy['detail'] !== '') : ?> <span class="muted">— <?= h($allergy['detail']) ?></span><?php endif; ?></legend>
                    <?php foreach (Review::ALLERGY_CHOICES as $code => $label) : ?>
                        <label class="choice"><input type="radio" required name="allergies[<?= h($allergy['id']) ?>][status]" value="<?= h($code) ?>"> <?= h($label) ?></label>
                    <?php endforeach; ?>
                    <input class="note" type="text" maxlength="500" name="allergies[<?= h($allergy['id']) ?>][note]" placeholder="What is wrong? (optional)">
                </fieldset>
            <?php endforeach; ?>
        <?php endif; ?>
        <label class="block">Allergies that are not on the list, and what happens
            <textarea name="allergies-new" rows="3" maxlength="2000"></textarea></label>
    </section>

    <section class="card">
        <h2>Questions for your visit</h2>
        <?php if ($conditions !== null && $conditions !== []) : ?>
            <p class="muted small">On file: <?= h(implode(', ', $conditions)) ?></p>
        <?php endif; ?>
        <label class="block">Anything you want to ask or tell your care team
            <textarea name="questions" rows="4" maxlength="2000"></textarea></label>
    </section>

    <?php if ($questionnaire !== null && $canSend) : ?>
        <button type="submit" class="primary">Send to my care team</button>
    <?php endif; ?>
</form>

<?php if ($submissions !== []) : ?>
    <section class="card">
        <h2>What you sent</h2>
        <ul class="plain">
            <?php foreach ($submissions as $submission) : ?>
                <li>
                    <?= h($submission['authored'] !== '' ? date('M j, Y g:i A', (int)strtotime($submission['authored'])) : $submission['id']) ?>
                    — <?= $submission['held'] ? '<span class="tag">waiting for review</span>' : '<span class="tag ok">in your record</span>' ?>
                    <?php if ($submission['held']) : ?>
                        <form method="post" action="index.php" class="inline">
                            <input type="hidden" name="csrf" value="<?= h((string)$visit['csrf']) ?>">
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="id" value="<?= h($submission['id']) ?>">
                            <button type="submit" class="link">Withdraw</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php
View::footer();
