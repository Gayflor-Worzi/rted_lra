# System Administrator Password Recovery

The login screen provides email OTP recovery for active System Administrator
accounts only. The API does not accept an email address or user ID during code
verification or password completion, and the same endpoints can be used by any
client without bypassing server-side eligibility checks.

## Local mail testing with Mailpit

The local development default is the Laragon Mailpit SMTP sink. Configure
`.env` to use it:

```dotenv
MAIL_MAILER=mailpit
MAILPIT_HOST=127.0.0.1
MAILPIT_PORT=1025
```

View captured messages at `http://localhost:8025/`. Mailpit stores messages
locally for inspection; it does not deliver them to real inboxes and must not
be exposed as the production mail server.

## Production mail delivery

Production may select `MAIL_MAILER=brevo` when the Brevo API key is configured
as `BREVO_API_KEY` in the backend deployment environment. The sender address
and name come from `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`; the address must
be verified with Brevo. Keep credentials out of source control. Do not
configure production to use the local Mailpit service.

The Brevo transport uses the HTTPS transactional email endpoint and sends the
API key only in the `api-key` request header. It has bounded connection and
request timeouts, certificate verification enabled by the HTTP client, and no
automatic retries. Provider rejection, connection failures, or a missing key
fail the send; response bodies and credentials are not included in application
error messages. Recovery and Brevo transport tests mock delivery and do not send
real email.

Recovery refuses `log`, `array`, `null`, and failover transports that include
them so OTPs are never written to application logs or silently treated as
delivered.

## Recovery security and data

- Request responses do not disclose whether an address belongs to an active
  System Administrator.
- OTPs are six digits, expire after five minutes, and are stored only as a
  password hash. Verification has a five-attempt limit.
- Request references and reset tokens are random, single-use, short-lived, and
  stored as SHA-256 hashes.
- Recovery requests are rate-limited by email-derived key and IP. New eligible
  requests invalidate earlier unfinished requests.
- Password recovery does not change `must_reset_password`; any existing forced
  reset remains enforced by the existing authentication middleware.
- Audit entries include the result, account ID when known, source, IP address,
  and user agent, but never the OTP, reset token, password, or password hash.
- A new additive migration adds `users.password_changed_at` and the recovery
  request table. Apply it only through the authorized deployment/migration
  process; this feature implementation does not run migrations.

The backend endpoints are:

- `POST /api/v1/auth/password-recovery/request`
- `POST /api/v1/auth/password-recovery/verify`
- `POST /api/v1/auth/password-recovery/complete`
