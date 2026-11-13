# Visit Prep: a patient portal SMART app for OpenEMR

An example SMART on FHIR app that a patient opens from OpenEMR's patient portal. Before a visit,
the patient reviews the medicines and allergies on file, says what has changed, adds anything
missing, and writes down questions for the visit. The app sends it all as one
QuestionnaireResponse, which OpenEMR holds for staff review until someone approves it.

It is small on purpose, so it can be read end to end and copied: plain PHP 8.1+, no
dependencies, no build step, no JavaScript.

## What it shows

- **Portal launch.** OpenEMR's portal opens the app with `iss`, `aud` and an opaque `launch`. The
  app asks for `launch` (not `launch/patient`), so the patient comes from the portal's launch, and
  OpenEMR gives that launch only to the patient it was made for.
- **Confidential client, PKCE S256.** The access token stays in the PHP session. The browser never
  sees it.
- **Reads.** Patient, active MedicationRequests, AllergyIntolerances, active Conditions and the next
  booked Appointment.
- **A patient app write.** A QuestionnaireResponse, held for review and tagged `held-for-review`.
  The app lists the patient's submissions as *waiting for review* or *in your record*, and can
  withdraw one that is still held by setting `status` to `entered-in-error`.
- **The app decides what is written.** The patient, the Questionnaire and the records reviewed come
  from the token and the server. The form only carries the patient's answers for records the
  app showed, so a forged field changes nothing.
- **When a feature is off.** Without Patient App FHIR Writes the page is read-only and says why.
  Without the Questionnaire it says the practice hasn't set Visit Prep up yet.
- **Safe page defaults.** A strict Content-Security-Policy, CSRF tokens on every POST, and an
  allowlist of registered issuers, so a forged `iss` never receives a code.

## Requirements

- An OpenEMR with the patient portal SMART launcher
  ([openemr/openemr#15272](https://github.com/openemr/openemr/pull/15272), for
  [#7115](https://github.com/openemr/openemr/issues/7115)).
- For sending (optional): patient app writes, which OpenEMR has since #15162.
- PHP 8.1+ with curl, a web server, and HTTPS.

## Set up

1. **Install.** Put this folder on a web server so `public/` is reachable, for example
   `https://localhost/oe-visit-prep/public`. The `.htaccess` files deny everything outside
   `public/`; on nginx, make `public/` the root.
2. **Configure.** Copy `config.sample.php` to `config.php` and set `app_url` to the public URL of
   `public/`.
3. **Turn on in OpenEMR** (Admin → Config → Connectors):
   - the FHIR REST API and the patient portal;
   - to let patients send: **Patient App FHIR Writes**, set to *Confidential apps* or *Confidential
     and public apps*, with **QuestionnaireResponse** ticked.
4. **Register.**
   ```bash
   php bin/register.php --iss=https://localhost/openemr/apis/default/fhir
   ```
   It asks only for the scopes the server publishes, and says if patients can't send yet.
5. **Enable the client** in OpenEMR under Admin → System → API Clients.
6. **Add the Questionnaire** once, with a staff access token that has `user/Questionnaire.write`
   (for example from the API Explorer's Confidential client):
   ```bash
   php bin/seed-questionnaire.php --iss=https://localhost/openemr/apis/default/fhir --token=<staff access token>
   ```
   Or POST `questionnaire/visit-prep.questionnaire.json` to `/fhir/Questionnaire` yourself.

## Use it

1. The patient signs in to the portal and opens **Health Apps**, then **Launch** next to Visit Prep.
2. OpenEMR asks the patient to sign in (Patient Login) and to allow access.
3. The patient answers for each medicine and allergy, adds what's missing and any questions, and
   sends.
4. Staff review it under **Miscellaneous → Patient App Submissions**. Approved, it is in the chart
   as a QuestionnaireResponse. Until then the patient can withdraw it.

## How it works

| File | Role |
|---|---|
| `public/launch.php` | The launch URI. It checks the issuer is registered, discovers the SMART endpoints, and redirects to authorize with `launch`, `aud` and PKCE. |
| `public/callback.php` | The redirect URI. It checks the state, exchanges the code with the client secret and verifier, and keeps the token in the session. |
| `public/index.php` | The review page: reads the record, sends the QuestionnaireResponse, withdraws a held one. |
| `src/Review.php` | What is read, how the Questionnaire is found (title plus canonical, as `url` or `identifier`), and how the response is built from the form. |
| `src/Smart.php`, `src/Fhir.php`, `src/Http.php` | Discovery, PKCE, the token exchange, and FHIR calls. |
| `bin/register.php` | Dynamic client registration: `application_type: private`, `initiate_login_uri` as the launch URI. |
| `questionnaire/visit-prep.questionnaire.json` | The Questionnaire: a group per medicine and allergy on file (name, record reference, a choice, a note), plus free text for what's missing and questions. |

OpenEMR publishes its own `url` for a Questionnaire it stores, so the app recognizes Visit Prep by
its title together with its canonical, whether that is in `url` or in an `identifier`
(`urn:ietf:rfc:3986`).

## Test

```bash
php tests/e2e.php
```

This runs the app against `tests/mock-ehr.php`, a small stand-in for OpenEMR's SMART and FHIR
endpoints. It covers registration, portal launch, review, send held for review, withdraw, and a
practice that doesn't accept app writes.

## License

GNU General Public License v3. See [LICENSE](LICENSE).
