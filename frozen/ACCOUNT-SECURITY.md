# Offline account provisioning and activation

This batch adds password-based provisioning and account activation only. It
does not implement MFA or password recovery. No mail, SMS, cloud or internet
service is used for provisioning, activation or password checks.

## Fresh packaged installations (Windows and Mac)

The installer distinguishes a new installation by the absence of the local
SQLite database before migration. It creates `config/setup/` with an inherited
ACL restricted to Windows Administrators and SYSTEM **before** issuing a code.
It invokes `system:first-admin --initialize --output-file=... --json` through
the bundled PHP. No account or usable password exists at this point.

The authorized local installer opens `config/setup/first-admin.txt`, privately
delivers the code to the owner and deletes that delivery file after handover.
The owner opens `/setup`, enters the code and creates/confirms a personal
password. Then the owner signs in normally. Never append a code to a URL.
The code is not printed to the terminal, installer result or transcript.
Windows installer identity options belong to its reviewed fresh installation procedure; activation does not change that identity. Mac delivery uses its protected shared setup directory.

Codes contain 256 bits from `random_bytes`, last 24 hours and allow five
credential attempts. Only SHA-256 hashes are stored in the database. The
protected local delivery file is the temporary plaintext handover copy; the
app cannot redisplay a code. Setup and activation pages use no-store responses.

For a manual fresh install, first create a private local delivery directory
(Windows: Administrators/SYSTEM only; Unix: owner-only directory, mode 0700).
Run the following with the installation's PHP and php.ini, substituting the
individual identity and protected path:

```text
php artisan system:first-admin --initialize --email=owner@school.local --name="Full Name" --output-file=C:\PHILCST-VMS\config\setup\first-admin.txt --json
```

`--json` contains only issuance status, never a credential. Do not redirect
codes into general logs. `--output-file` must be outside `public/`.

## Existing and interrupted installations

Use [the current operator/lifecycle guide](OPERATOR-GUIDE.md). Legacy adoption
and unknown update paths remain refused. Exact same-source re-entry verifies
without reseeding or reissuing setup. D5B/C/D controllers own data-preserving
recovery/update/transfer; do not manually replace code, migrate and restart.
Keep account_security_state, security_events, keys and session-version state in
coherent private backups. Completed setup cannot be reopened by deleting users.

Interrupted fresh finalization resumes only its protected recorded intent.
Earlier incomplete native installation or lost/expired setup delivery needs
explicit local recovery review. Never regenerate APP_KEY or seed a demo account.
Do not rerun an installer with code-replacement switches as an access bypass.

## Individual accounts

An active admin with an updated password opens **Accounts**, creates an
individual admin/guard with a fixed name, email and role, and sees one
activation-code response. Deliver it personally. The owner opens `/activate`,
enters the assigned email and code, and creates/confirms a personal password.
The owner is then directed to sign in. Pending accounts cannot sign in or
access authenticated feeds, live view or operational pages.

Only an authorized admin can replace a pending account's code. Replacement
immediately invalidates the old code, resets attempts and starts a new 24-hour
period. Activation cannot change account identity or role. Active accounts
cannot be reactivated with an old code. Admins can change active accounts'
roles/status; these actions revoke their sessions. The last active admin
cannot be disabled or demoted. There is no account-deletion endpoint.

## Passwords, sessions and audit

Passwords require at least 15 Unicode characters. Spaces, paste and
passphrases are supported, with no character-composition rules. Passwords
are never trimmed. A bundled offline blocklist plus PHILCST-specific checks
reject common/contextual weak passwords. Comparisons are normalized for
blocklist matching only, never for hashing.

`config/hashing.php` selects Argon2id when available (64 MiB, three passes,
one thread), allowing up to 1,024 UTF-8 bytes. Windows release self-checks
require Argon2id support in the bundled PHP; local runtime inspection and
tests verify support here, but a Windows execution is still required for a
release. Existing bcrypt/Argon hashes use `password_verify` through Laravel;
successful logins rehash when needed. For other PHP installations without
Argon2id, the safe compatibility fallback is Laravel bcrypt with a strict
72-byte input limit, rejecting longer new passwords instead of truncating.
This supports 64 ASCII characters; Unicode inputs are bounded by bytes.
Do not downgrade an Argon2id installation to a runtime unable to verify it.

`/account/password` requires the current password. A successful change rotates
the session ID and remember token and increments `session_version`; every
authenticated browser/API request checks this version, rejecting other old
sessions across file/database session drivers. Legacy policy-version-zero
sessions may use only password change and logout. Detector API keys and
background services have separate authorization and continue after logout.

`security_events` records code issuance/replacement, first-admin creation,
account creation/activation, rejected code attempts, permission changes and
password changes using actor/subject IDs, action names and timestamps. It
never stores submitted passwords, codes or complete request payloads.

## Release boundaries and separate security work

Production seeders contain no account fixtures. Test accounts live under
`tests/fixtures`; tests and database factories are excluded from release
archives. Source export excludes local databases, environment secrets,
runtime files, sessions and backups; the build additionally rejects local
installation data in the exported app before packaging. Existing tracked
historical backups remain locally preserved but excluded from these release
artifacts; this does not remove anything from repository history.

For required TOTP in the next batch, extend `EnsureAccountReady` after the
account-state, session-version and password-policy checks. Add MFA enrollment
and challenge-only routes to its restricted-route policy; require completion
for both admins and guards before operational access. Setup and activation
currently establish a personal password and direct to normal sign-in. Never
apply interactive MFA checks to detector-service API-key authorization.
