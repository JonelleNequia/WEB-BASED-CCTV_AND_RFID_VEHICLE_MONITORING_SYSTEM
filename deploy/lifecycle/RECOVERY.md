# D5B same-installation backup and recovery

Use only administrator-owned tools/runtimes from a verified **new D5B package**.
This is a source/fixture implementation; native Windows/Mac system recovery acceptance
is still required. D2/D3 archives do not contain D4/D5A/D5B. Do not copy source into a
working installation or run live migrations as an implicit upgrade.

D5B supports the same installation, machine, root path, platform, exact source/schema
and runtime identity. It does not authorize D5C updates or D5D transfers. The additive
`recovery_state` migration is used on fresh isolated installs in verification only.
Installed D5A identity, DB/spool/barrier and protected D4 issuer must already exist.
Missing metadata or keys are a refusal, never a reason to generate replacement keys.

## Trusted controller

Windows, Administrator PowerShell:

```powershell
$root='C:\PHILCST-VMS'
$py="$root\runtime\python\python.exe"
$recovery="$root\app\deploy\lifecycle\recovery.py"
$maintenance="$root\app\deploy\lifecycle\maintenance.py"
& $py -B $recovery status --root $root
# One explicit first registration; existing/incomplete registration is not overwritten.
& $py -B $recovery initialize --root $root
& $py -B $recovery backup --root $root
```

Mac, sudo, using the native architecture package:

```sh
ROOT='/Library/Application Support/PHILCST-VMS'
PY="$ROOT/current/runtime/python/bin/python3"
RECOVERY="$ROOT/current/app/deploy/lifecycle/recovery.py"
MAINTENANCE="$ROOT/current/app/deploy/lifecycle/maintenance.py"
sudo "$PY" -B "$RECOVERY" status --root "$ROOT"
sudo "$PY" -B "$RECOVERY" initialize --root "$ROOT"
sudo "$PY" -B "$RECOVERY" backup --root "$ROOT"
```

Windows `scripts/backup-now.ps1` / `scripts/restore.ps1` and Mac
`bash .../app/deploy/macos/recovery.command ACTION ...` call the same engine. No browser
request can start privileged recovery. Keep the administrator command output private.

Default output is `state/recovery/backups` (Windows) or `shared/recovery/backups` (Mac).
`--output` can name a **new** private destination or an already administrator-private
ACL-capable local directory. A complete set is a UUID directory containing `backup.zip`
and `receipt.json`; retain both. Private staging and retained rollback material also
consume space. No automatic retention or deletion is performed.

The ZIP is **unencrypted** and contains passwords, APP_KEY, API and TLS private keys,
accounts and evidence. Protect external drives with an institution-approved encrypted
volume and independent recovery-key custody. D5B implements no custom encryption or
backup password. Hashes detect corruption, not the identity of the backup author; obtain
backups through separately verified administrator custody. Never serve them from public
storage or attach them to diagnostics. Export/USB copying and private ACL verification
remain the administrator's responsibility.

## Restore plan and explicit approval

`inspect` and `plan` are read-only for installed state, using bounded private temporary
staging. They validate all bytes, schema, secrets, issuer, evidence and installation.
A final approval should be obtained **after Enter**, because any new data changes the
confirmation hash. `--accept-later-data-loss` acknowledges replacement of later activity;
D5B does not merge later movement records into an older database.

PowerShell example (use the operation UUID chosen by you):

```powershell
$op=[guid]::NewGuid().ToString()
$backup='E:\PrivateRecovery\BACKUP-UUID'
& $py -B $maintenance enter --root $root --operation $op
& $py -B $recovery plan --root $root --archive $backup
# Read recovery point, data-loss policy, defects and full 64-character confirmation.
& $py -B $recovery restore --root $root --operation $op --archive $backup `
  --confirm-plan 'EXACT_PLAN_CONFIRMATION' --accept-later-data-loss
& $py -B $recovery status --root $root
& $py -B $recovery accept --root $root --operation $op
& $py -B $recovery resume --root $root --operation $op
```

Mac uses the same arguments with `sudo "$PY" -B "$MAINTENANCE"` for Enter and
`sudo "$PY" -B "$RECOVERY"` for the remaining commands; quote `"$ROOT"` and paths.
If the plan lists existing foreign-key defects, inspect them separately. The additional
`--acknowledge-known-defects` is required to proceed; it does not repair or delete them.
Wrong origin, missing referenced image, undecryptable secret, changed APP_KEY/CA,
unsupported storage or missing monotonic state is rejected before promotion.

Backup pauses capture/delivery, scheduler, PHP, live relay and **Caddy certificate writes**,
and waits for in-flight writer reservations. Monitoring is down for this interval. Pending
work already persisted under D5A is included with its original evidence and matching
server receipts. Vehicles never captured during downtime cannot be reconstructed.
Successful backup verifies and resumes automatically. Any failure retains maintenance.

Restore preserves a verified current safety backup before staging reconciliation. Incoming
code/configuration is never executed. Current environment/native service configuration,
TLS identity/issuer, network/ownership receipts and update trust are preserved; restored
DB device settings/calibration and evidence are verified together. Generated camera/relay
configuration and caches are rebuilt using installed code during explicit Resume.
No OS service plist/registry, firewall, hosts, router, DNS or client trust is imported.

## Interrupted operations

Multi-file promotion is a **journaled sequence, not one atomic filesystem transaction**.
Protected journal states and expected hashes bind each retained old and staged component.
Startup/admission remains blocked until the verified set is accepted and resumed. Raw
prior files and the safety archive remain private after acceptance; no automatic cleanup.

```text
status
[only if the recorded owner process is dead] maintenance.py recover --operation UUID
recovery.py recover --operation UUID --decision forward
# OR explicitly choose rollback, never guess from file presence:
recovery.py recover --operation UUID --decision rollback
recovery.py accept --operation UUID
recovery.py resume --operation UUID
```

All commands require the same `--root` as above. A failed backup or failure before any
promotion intent can instead use `recover --decision current`, then `resume`, after full
current-state validation. Interrupted initialization can complete only its recorded exact
host/security intent through `recover --decision current`; conflicting/missing journal
intent needs separately reviewed recovery. Do not delete locks, receipt files, or the
security ledger to force a start. A live recorded owner cannot be stolen. A failed
rollback or unverified current/staged file keeps all writers fenced. An accepted set
cannot be rolled back through the same operation after newer activity may exist.

If schema/source/runtime differs, no migration is attempted: unapproved origins remain
rejected for D5C. If both the machine and its latest protected state are lost, an old backup
cannot recover later lost-UID decisions, revoked update keys, account/security changes or
activity that were never preserved elsewhere. D5D must define that disaster/transfer path.

## Security and delivery policy

- Union permanent denies by normalized **UID**, never numeric tag ID. Matching restored
  inventory becomes lost/unassigned; underlying historical events remain intact.
- Preserve latest account passwords/status/roles/security events by checked identity;
  account identity/audit collisions refuse recovery. All restored sessions, remember
  tokens, activation/reset/setup codes are invalidated. Completed first-admin setup stays
  closed. Removed historical users remain disabled so attribution is retained.
- Keep current software trust roots; union revocations and retain the highest verified
  trust generation. Do not invent rotations or enroll archive-supplied roots. TLS and
  software-update trust remain separate. Restore increments a protected recovery epoch
  and binds it to the restored database.
- Retained pending work and prior receipts keep their original identity/generation.
  Repeated receipt verification does not apply movement effects again. Known operations
  after the selected point are quarantined from reapplication on that restored set;
  their original bytes/receipts remain in the safety set for separately reviewed recovery.

## Schedules and native acceptance

Windows' existing backup task remains **disabled by default**. Its command now invokes
this same coherent engine, never Artisan's legacy backup. Enable it only after native
service/ACL/PS5.1 acceptance and an approved downtime/retention schedule. The adapter
exempts only the verified currently executing owned backup task from stopping itself;
other owned tasks/services are disabled/stopped and their prior state is restored only
after successful verification. Mac does not install a new schedule; an explicit approved
administrator schedule must call this same bundled engine. Overlapping controllers refuse.

Tests use private complete Laravel installations and OS service/ownership adapters.
Windows PS5.1/ACL/locked-file semantics, Mac launchd/Keychain/service-user execution,
real reboot/power loss, encrypted removable-volume custody and physical hardware are
**NOT RUN**. SIGKILL and synthetic ACL/disk failures do not certify those scenarios.
