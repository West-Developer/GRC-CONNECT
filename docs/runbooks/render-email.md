# Email delivery on Render Free

Render Free blocks outbound SMTP ports 25, 465, and 587. Gmail SMTP on port 587
therefore cannot deliver the account, queue, password-reset, or enrollment emails
from that hosting plan. This is a hosting restriction, independent of the
student-account workflow. See [Render's limitations](https://render.com/docs/free#other-limitations).

The application supports `resend_http`, a small transport that sends the existing
Laravel mail templates to the [Resend HTTPS API](https://resend.com/docs/api-reference/emails/send-email)
on port 443 using Laravel's installed HTTP client. No provider SDK is required.
The existing `smtp`, `array`, and SDK-based `resend` mailers keep their own behavior.

## Configure the deployed backend

1. In the school's Resend account, verify a sending domain using the DNS records
   Resend supplies. Use an address on that verified domain as the sender; a Gmail
   address cannot be verified as the school's Resend sending domain.
2. Create an API key with sending access for that domain. Save the key only in
   Render's backend service Environment settings, never in git, screenshots, or
   support messages.
3. Set these backend environment variables, replacing the placeholders with the
   school's configured values:

   ```dotenv
   MAIL_MAILER=resend_http
   RESEND_KEY=<sending API key from Resend>
   MAIL_FROM_ADDRESS=<address on the verified sending domain>
   MAIL_FROM_NAME="GRC Enrollment System"
   MAIL_HTTP_TIMEOUT=15
   FRONTEND_APP_URL=https://www.grc-enrollment.tech
   QUEUE_CONNECTION=sync
   ```

   Keep the current frontend URL if the deployment uses a different hostname.
   `QUEUE_CONNECTION=sync` matches the application's current synchronous email
   actions and does not require a separate queue worker. SMTP host/port/password
   values are unused while `MAIL_MAILER=resend_http` is selected.
4. Deploy the transport code and rebuild Laravel's configuration cache as part of
   the Render deployment (`php artisan config:cache`). The code change alone does
   not create a Resend account, verify DNS, or configure the live service.

Do not select `MAIL_MAILER=resend`: that is Laravel's separate SDK transport and
requires `resend/resend-php`, which this repository does not install. Do not use
`MAIL_MAILER=array` for live delivery; it keeps messages in memory for local tests.
Do not fall back to the `log` mailer for account emails, since setup/reset messages
contain one-time codes.

Student setup and queue email actions refuse `array`/`log` in production, including
failover or round-robin configurations containing either sink. They record a
resendable delivery failure while keeping the student/payment/queue ticket. The
guard runs inside those email actions and does not prevent the API from starting.
Local and testing environments retain their existing mail fakes.

## Verify after configuration

- Check Render's deployment/startup result and that the API health endpoint works.
- For an authorized test student, use the existing Admission **Resend setup email**
  action and check the displayed delivery status, then Resend's delivery record and
  the recipient's inbox/spam folder. A provider-accepted message is not proof that
  it has reached the inbox; domain verification, quotas, bounces, and mailbox
  filtering remain provider/recipient concerns.
- A new freshman receives the queue-number email at Admission. Their account-setup
  email is sent after the Cashier confirms the first payment (ADR 0043). A
  Transferee, Returnee, or Existing Student receives account setup at creation.
- A failed email does not undo the saved student or payment. Correct the email
  service/address and resend from Admission; do not create a duplicate account.

The transport reports HTTP status codes without response bodies, API keys,
recipient addresses, setup codes, or nested HTTP exceptions. An HTTP 401/403 calls
for checking the API key/sending domain; 422 calls for checking sender/message
validation; 429 calls for checking the provider's quota. A connection failure calls
for checking HTTPS egress and the provider's availability. Consult Resend's private
dashboard for detailed provider errors. The transport does not automatically retry
an ambiguous send, since the provider might already have accepted it.

## Local verification and rollback

`php vendor/bin/phpunit tests/Unit/Mail` exercises the transport through HTTP fakes
and the production sink guard; it needs no provider key, external email, or
database. `ResendStudentAccountSetupTest` and `SendFreshmanQueueTicketEmailTest`
cover failed delivery metadata on a synthetic database. Existing invitation and
payment tests retain the freshman payment boundary.

For a local workstation with functioning SMTP, keep `MAIL_MAILER=smtp`. The default
`.env.example` stays `array` for safe development. To revert a hosting change, restore
the prior mail environment settings and rebuild config cache; Gmail SMTP on Render
Free will still be blocked. No migration or student-record change is involved.
