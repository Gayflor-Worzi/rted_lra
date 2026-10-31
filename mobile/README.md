# Mobile App Notes

## Protected offline reads

Android and iOS builds use SQLCipher for the app database and SecureStore for the database key and session token. Existing plaintext mobile databases are copied into an encrypted database before the old file is removed; queued submissions are preserved during that migration. If encryption or migration fails, the app stops initialization rather than opening the data without protection.

Offline reads are scoped to the signed-in user and role and expire by module: dashboard after 24 hours; tasks, discoveries, valuations, notices, and GPS after 48 hours; organization data after 7 days. Expired data is not displayed. Task-cache entries omit taxpayer identity and payment amounts/status details; payment notices and payment-history screens are not cached. Cached records cannot be used to perform offline mutations. The Expo web target does not persist offline copies because it does not provide the native encryption used for mobile.

## Android release signing

The Android release build requires an existing production keystore. Do not use the debug keystore or put signing credentials in source control. Set these environment variables in the local build terminal before running a release task:

- `RETD_RELEASE_STORE_FILE`
- `RETD_RELEASE_STORE_PASSWORD`
- `RETD_RELEASE_KEY_ALIAS`
- `RETD_RELEASE_KEY_PASSWORD`

The current app version is `1.0.4` with Android `versionCode` `2`. Increment the version code for each subsequent Android release. Release tasks fail if the required signing variables are missing.
