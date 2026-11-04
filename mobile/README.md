# Mobile App Notes

## Protected offline reads

Android and iOS builds use SQLCipher for the app database and SecureStore for the database key and session token. Existing plaintext mobile databases are copied into an encrypted database before the old file is removed; queued submissions are preserved during that migration. If encryption or migration fails, the app stops initialization rather than opening the data without protection.

Offline reads are scoped to the signed-in account and expire after 7 days for dashboard, tasks, task details, visits, bills, discoveries, valuations, GPS, and organization data. Notices may be retained for up to 30 days; payment notices and payment-history screens are not cached. Expired data is not displayed. Task-cache entries omit taxpayer identity and payment amounts/status details, and task-detail cache entries omit payment/verifications. Visit history is limited to the fields displayed in the history view and omits payment snapshots. Cached records provide field context only and cannot authorize offline mutations. The Expo web target does not persist offline copies because it does not provide the native encryption used for mobile.

## Offline submissions

Field submissions are stored in the account-scoped encrypted outbox before synchronization. Discovery, valuation, and visit records are committed with dependent evidence as atomic bundles, then uploaded in dependency order after connectivity returns. Persistent encrypted form drafts are scoped to the signed-in account, including new records, receipt/visit/GPS capture, task engagements, bill amendments, and discovery/valuation action forms. Individual evidence files are compressed and limited to 10 MB; discovery, valuation, and visit evidence limits are 3, 3, and 5 photographs respectively. Unsubmitted outbox rows and drafts are not automatically removed. Successfully synchronized outbox history is retained for 30 days.

A record or evidence item is shown as synchronized only after server confirmation; failed or interrupted submissions remain available for review and retry. Server idempotency keys protect retries against duplicate records. Receipt submissions and on-the-spot payment claims remain claims pending verification: offline storage is not confirmation of payment. Payment verification/confirmation/rejection, escalations, assignment changes, direct official GPS corrections, and workflow decisions that require an authoritative server decision remain online-only; their entered form values may be retained encrypted as drafts.

## Native Android configuration

The checked-in `android/` project is the source of truth for Android application ID, version code, permissions, launcher resources, theme, and native module settings. EAS builds include this project; do not exclude it or run Prebuild without reviewing the generated native changes. SQLCipher is enabled by `expo.sqlite.useSQLCipher` in `android/gradle.properties`.

## Android release signing

Android release builds require the existing production keystore. EAS builds use the project's existing remotely managed Android credentials and inject their signing configuration during the cloud build. The Gradle check accepts that EAS-injected configuration while continuing to require the local signing variables for local release builds. Do not use the debug keystore or put signing credentials in source control.

For local release builds only, set these environment variables in the local build terminal before running a release task:

- `RETD_RELEASE_STORE_FILE`
- `RETD_RELEASE_STORE_PASSWORD`
- `RETD_RELEASE_KEY_ALIAS`
- `RETD_RELEASE_KEY_PASSWORD`

The current app version is `1.0.4` with Android `versionCode` `2`. Increment the version code for each subsequent Android release. Local release tasks fail if the required local signing variables are missing.
