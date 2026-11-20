# Deployment D5B — Consistent backup, restore and failure recovery

20 November 2026, Asia/Manila. D5B only. **Source implementation and synthetic recovery
verification are separate from native installed acceptance and rebuilt packages.** No
production installation or update origin is certified by this report. Stop after D5B;
wait for **Go Deployment D5C**.

## Findings and corrections

Read the D5B attachment, D1/runtime inventory, D2, complete D3/update amendment, D4 and
D5A. No applicable AGENTS.md was found in the checkout/ancestors or affected directories.
The actual D5A source was traced and its **31 isolated tests passed before editing**.
It supplies a retained installation/generation, durable immutable evidence/receipts,
SQLite writer reservation, producer drain, owned service/task stops, dead-owner review
and explicit gated resume. D5B holds its owner lock and writer reservation throughout
capture/promotion, rather than treating a maintenance flag alone as quiescence.

The old `BackupService` copied SQLite and then independently walked media, omitted
outbox/issuer/network/trust/security context, and replaced DB before media/key completion.
Old Windows restore could restart services from a finally block after failure. A backup
schedule using that path could misleadingly succeed. D5A guarded installed legacy paths,
but did not implement a coherent recovery transaction.

Implemented corrections:

- Replaced operational Windows wrappers/schedule command with the shared external D5B
  controller; added a Mac installed helper. Old `backup:run`, `backup:restore`, and direct
  BackupService mutation now refuse with D5B instructions. Legacy archive listing/inspection
  remains read-only and explicitly unverified. No browser route executes privileged tools.
- Kept existing authorization and installed D5A reset guards. Audited Settings reset,
  SystemResetService backup/backupDatabase, console hooks and scheduler: installed D5A
  continues to reject those uncertified reset paths. Development-only reset behavior is
  unchanged; its old snapshot is not advertised as a complete D5B recovery set.
- Added complete sensitive recovery sets, read-only plans, concrete approval, verified
  pre-restore safety backup, staged reconciliation, per-component journal/hashes, explicit
  forward/rollback/current recovery, acceptance and resume. No unconditional restart.
- Added DB/protected recovery epoch binding, permanent UID/revocation/account protection,
  and quarantine of known deliveries outside the selected recovery point.
- Added startup/admission checks so a missing/blocked recovery receipt or epoch mismatch
  cannot be bypassed by ordinary maintenance resume or automatic service restart.
- Runtime regeneration now explicitly avoids `ensureRunning` from Settings export. Normal
  callers retain the previous default; recovery writes config without probing/restarting
  the live relay. A focused Laravel mock verifies this boundary.

## Versioned backup contract and consistency boundary

`deploy/lifecycle/backup_contract.py` is the authoritative `philcst.backup.v1` validator.
A completed backup is a new UUID directory with **backup.zip + receipt.json**. The receipt
binds archive size/hash, manifest hash and completion. Archive BACKUP.json inventories
all included files by exact path, SHA-256 and size, required/optional component roles,
creation instant with timezone/display context, product/source/build/runtime/schema IDs,
installation/generation/host binding, stopped maintenance state and validation results.
It is distinct from D3 software-update and package content manifests.

Synthetic shape (abbreviated inventory; not an importable backup):

```json
{
  "schema": "philcst.backup.v1",
  "product": "philcst-vms",
  "backup": "11111111-1111-4111-8111-111111111111",
  "created_at": "2026-11-20T00:00:00+00:00",
  "timezone": "Asia/Manila",
  "compatibility": {"platform": "macos-arm64", "version": "1.0.0-fixture.1", "source_sha256": "<sha256>", "build_identity": "SYNTHETIC", "commit": "<40 hex>", "schema_id": "<sha256>", "runtime_id": "<sha256>"},
  "installation": {"identity": {"schema": 1, "installation": "<uuid>", "generation": "<uuid>"}, "host_sha256": "<sha256>"},
  "maintenance": {"schema": 1, "phase": "quiescent_pending", "operation": "<uuid>"},
  "components": {"database": {"present": true, "kind": "file"}, "storage": {"present": true, "kind": "directory"}},
  "files": {"payload/database/data": {"size": 12345, "sha256": "<sha256>"}},
  "validation": {"complete": true, "database": {"schema_sha256": "<sha256>", "migrations": [], "foreign_key_defects": []}, "delivery": {"operations": 1, "pending": 1, "receipts": 0}}
}
```

Actual roles include database, environment, storage, configuration, TLS, network,
TLS identity, public root, server-trust receipt, update trust/state, setup, installation
receipt, D5A identity/delivery/coordination, and protected security ledger. Optional roles
must still be explicitly recorded absent. Missing update trust is accepted only when
it was never provisioned and no protected trust generation/revocation was retained.
No signing root is bootstrapped from an incoming backup.

Contents include accounts/provisioning/security/audit rows, gates/assignments/calibration,
original outbox envelopes/media/ACK state and corresponding server receipts, permanent UID
restrictions, full persistent issuer/private web island, identity and owned exposure receipts,
and private RFID JSON exports. Image references cover VehicleEvent, VisitorRecord,
VehicleCrossing, GuestVehicleObservation and retained legacy GuestVisit snapshots. Required
referenced media/exports must exist. No history is repaired or deleted to pass inspection.

D5A Enter closes new capture, finishes registered producer work, stops owned producers and
scheduler, drains retained delivery, waits for in-flight PHP/Python writer reservations,
then stops PHP/live/**Caddy**, including certificate renewal. Every role is verified stopped.
Capture keeps the controller owner and writer reservation. App, outbox and coordination DBs
are normalized SQLite snapshots while media/configuration/keys remain stable. Unknown spool
files or producer jobs reject capture. Pending work is allowed only with matching immutable
evidence, installation/generation and canonical ACK/receipt consistency.

Logs, sessions/view/cache files, old backup folders and derived relay/Ultralytics state are
excluded. Unreferenced public/occurrence images are not resurrected. Other local device/
calibration/config state is retained. External/custom media/archive stores are rejected.
OS-owned services, launchd, registry, ACL definitions, hosts, firewall, DNS and client trust
are not restored from an archive. Machine paths are checked against the same installation.

Capture is verified before ZIP publication; ZIP/receipt are re-read and hashed, then their
new directory is published by rename. Incomplete destinations retain `.incomplete-*` names
and cannot replace a completed backup. POSIX directory/file fsync and SQLite commits are
used. There is no claim that multiple filesystem replacements form one atomic transaction.

Limits: 250,000 files, 4 GiB per file, 32 GiB expanded total, 16 MiB JSON metadata, with
available private staging/rollback and destination space checks. No automatic retention or
cleanup is implemented. Backups and staging are administrator-private; **the ZIP is not
encrypted**. Use approved encrypted-volume custody/key recovery for removable copies.
Self-contained hashes establish corruption detection, not backup-author authentication.

Monitoring pauses during backup/recovery. Persisted D5A evidence survives; physical traffic
never captured during downtime cannot be reconstructed. No uptime/large-install performance
or physical power-loss guarantee is inferred from fixture tests.

## Inspection, authorization and operator workflow

Exact commands and failure decisions: [RECOVERY.md](deploy/lifecycle/RECOVERY.md).
Installed native administrator + bundled trusted Python/PHP + D5A identity + protected D4
issuer + the D5B additive schema are required. Explicit `initialize` registers host/security
intent once; incomplete/conflicting registration cannot silently regenerate state.

One controller interface supports Windows and Mac:

```text
recovery.py initialize --root ROOT
recovery.py backup --root ROOT [--output PRIVATE_NEW_OR_ALREADY_PRIVATE_DIRECTORY]
recovery.py inspect --root ROOT --archive COMPLETE_BACKUP_DIRECTORY
maintenance.py enter --root ROOT --operation UUID
recovery.py plan --root ROOT --archive COMPLETE_BACKUP_DIRECTORY
recovery.py restore --root ROOT --operation UUID --archive COMPLETE_BACKUP_DIRECTORY
  --confirm-plan EXACT_64_HEX_CONFIRMATION --accept-later-data-loss
recovery.py accept --root ROOT --operation UUID
recovery.py resume --root ROOT --operation UUID
```

Run with the installation's bundled interpreter, elevated on Windows or via sudo on Mac.
Plan/inspect read active state without changing it, using private temporary files. Validation
covers ZIP metadata/paths, links/devices, duplicate/case/NFC collisions, shadowing, bounds,
missing/unexpected/truncated bytes, SQLite integrity and migration/schema identity,
representative real camera-secret decryption, media, receipts/spool, environment, issuer
keys/chain/time/root receipts and separately protected D3 update trust/state contracts.
Only installed validators/autoloaders/interpreters/ini are used; incoming code never runs.

The final plan binds archive, source compatibility, recovery point, current DB/spool/component
state and security epoch. Obtain it after Enter so concurrent changes cannot stale approval.
A blank/old confirmation or blanket Windows `-Yes` is rejected. Existing FK defects appear
in the plan and require `--acknowledge-known-defects`; introduced defects are rejected.
No migrations are run by restore. Different source/schema/runtime origins need D5C approval.

## Journal, promotion and startup behavior

Private state is under `state/recovery` on Windows and `shared/recovery` on Mac, alongside
D5A lifecycle metadata. Protected host registration binds machine/root/install. Operation
journals identify preparation, safety verification, staged forward/rollback sets, monotonic
security intent, expected old/new hashes, per-component rename intent/completion, verification,
acceptance and failure. Readable lifecycle guards bind the private journal's hash/status.

Before any active replacement, a complete verified current recovery set is published.
Both desired and rollback sets are staged and reconciled, validated, permissioned and synced.
The new security intent is journaled before its protected ledger is advanced. Database WAL/
SHM/journal sidecars are controlled separately; the live barrier inode is **never replaced**.
Each DB/storage/env/native-config/trust/receipt component moves through explicit retained-old
and staged-new steps. Expected hashes distinguish interrupted states; presence alone does
not authorize a guess. Media uses replacement of a controlled tree, not directory merging.

After promotion, full DB/key/media/receipt/environment/issuer/config/ownership proof is
repeated while fenced. Separate authorized `accept` retains raw rollback and safety backups;
`resume` regenerates trusted camera/relay/cache configuration and checks readiness before
producers reopen. Missing/corrupt state, unexpected hashes, failed permissions, readiness or
rollback leave writers fenced. Safe bounded failure reasons are available in private status;
vendor exception text/secrets are not echoed.

After a dead controller, first use D5A `maintenance.py recover` for the exact dead owner;
then D5B `recover --decision forward|rollback`. Before promotion, failed backups/initialization
can use `--decision current` only after full proof of unchanged/recoverable current state.
Active owners cannot be stolen. Neither accepted operations nor missing journals imply
permission to undo later activity. Physical reboot/crash native acceptance remains required.

Windows uses current owned service/task definitions and explicit role ACLs, including the
D4 Caddy virtual SID. It checks a running scheduled backup's exact task/action/controller/
interpreter/ancestor before exempting only itself from Stop; all other tasks/writers stop.
The task remains disabled pending native acceptance. Mac uses releases/current/shared and
installed service UID/group ownership; private web/issuer state stays root-only. No new Mac
schedule or OS configuration is installed. Both platforms use the same recovery engine.

## Permanent denies, trust and account security

Recovery unions lost/unavailable UIDs by normalized **UID**, preserving leading zeros and
avoiding recycled numeric tag IDs. Matching inventory becomes lost/unassigned; historical
records remain. New denies use explicit recovery provenance without fabricating an actor.
Original later evidence remains in the safety set.

Current software trust roots/state and native TLS/network configuration are retained rather
than replaced by archive-supplied roots. Revocations are unioned and the protected maximum
trust generation is enforced; an older current store is refused, never assigned an invented
rotation generation. TLS issuer and software-update trust are distinct systems.

Latest checked account identity/password/status/roles and security audit rows survive.
Recycled account identity or audit-ID collisions refuse recovery. Historical removed users
stay disabled. Session versions increase; sessions, remember tokens, activation/reset/setup
codes are invalidated. Completed first-admin setup remains closed. Incomplete setup or
conflicting identity requires separately reviewed provisioning; no demo account/code is made.

A monotonic protected recovery epoch and active-set ID bind restored DB to protected state.
Original D5A installation/generation and envelopes remain unchanged. Retained receipts replay
as verified ACKs without movement effects. Known newer operations outside the selected
recovery point are blocked for that restored set, with original bytes/receipts in the safety
set for separate review; rollback uses its own reviewed replay policy. Arbitrary later
activity is not merged into an older database.

If both the computer and its newest protected state are lost, an older backup cannot recover
later UID denies, revocations, account changes or movements never saved elsewhere. Missing
protected state is a refusal, not a reset. D5D must define authenticated disaster transfer.

## Verification and limits

All recovery fixtures use synthetic accounts, private DBs/storage/media/keys/TLS issuers,
original outbox work and OS service adapters. No working DB/secret was copied as a fixture.
The host is arm64 macOS 26.6.1. Native bundled **Python 3.12.16/PHP 8.4.24** executed the
same synthetic recovery tests from private current source; this does not turn the older
D3 payload into a D5B installation.

| Check | Executed result |
| --- | --- |
| D5B complete recovery/fault/security/HTTP suite, host Python 3.14/PHP 8.5.3 | **28 passed** |
| Same D5B suite, bundled arm64 Python 3.12.16/PHP 8.4.24 | **28 passed**, private current source/data, no system install |
| Full isolated Laravel, PHP 8.5.3, 1 GiB test-process memory limit | **888 tests: 887 passed, 1 skipped; 9,221 assertions** |
| Existing D5A lifecycle/delivery unit suite | **31 passed**, baseline and final |
| Real D5A helpers → four private PHP HTTP workers | Passed original evidence/time/receipt retention, lost ACK, transaction/fence race, camera-conflict quarantine, auth/generation rejection |
| Shared D2 package boundary / Mac policy suites | **14 + 19 passed** |
| D3 update contracts | **29 passed**; no origin approvals or trust roots changed |
| D4 network policy/detector-stream suite with native Caddy in private fixture | **17 passed**, including real local/LAN configure/rollback and persistent CA; no OS changes |
| Actual native Caddy high-port TLS/client fixture | Passed canonical SNI/root/name/auth/media boundaries, 25 PowerShell client checks, pinned same-root expired-leaf renewal and expired owned-root identity handling; no OS trust mutation |
| Windows common/preflight/lifecycle/recovery pure helpers | **17 + 13 + 30 + 9 passed**, macOS PowerShell 7 |
| PowerShell target/test parser | **26 scripts**, no syntax errors; PS5.1 native execution NOT RUN |
| Python/PHP/shell syntax and diff checks | Passed for changed recovery code; no whitespace errors |
| Existing complete archives | Rehashed all three; exact original bytes/hashes retained |
| Preservation | Working .env hash and staged index unchanged; no captured source files removed; preexisting OCR edits retained |

The Laravel skip is `StartupChecksTest::test_laravel_and_the_detector_work_out_the_same_code_version`:
the private app deliberately has no detector development `.venv`. The native bundled runtime
runs above are separate positive checks, not a substitute claim for that skipped assertion.

The 28 D5B tests include actual archive creation/restore on a full synthetic Laravel schema,
private linked pictures and legacy GuestVisit/RFID evidence; pending and lost-ACK delivery;
real local HTTP picture retry after restore; normalized lost UIDs; newer revoked trust,
account disable/session/setup protection; historical columns/relationships and explicitly
acknowledged existing FK defects; wrong host/source/platform/schema/runtime/key; corrupt,
missing/truncated/unsafe/duplicate/case/NFC/shadowed/oversized ZIPs; stale approval; disk-full,
permissions, locked DB rename and failed rollback; four DB/media/env/config failure boundaries;
three real SIGKILL boundaries (prepared journal, old DB moved, new storage installed);
interrupted initialization; in-flight writer completion and overlapping controller rejection;
configuration failure with retained fence; rejection of inherited shell DB/secret/cache
overrides; and bounded secret-safe error feedback. Simulated
faults and process-kill tests do not certify filesystem power-loss durability.

Earlier failed harness runs were
corrected without weakening application rules: private view/cache directories were missing;
a media retry correctly refused an unapproved replacement, so lost-ACK tests now retry the
original immutable context and a separate real-HTTP case exercises new picture delivery.
The extended D4 TLS helper initially lacked its test-only cryptography module; cryptography
46.0.3, cffi 2.1.1 and pycparser 3.12 were installed only under the private evidence directory,
then the complete fixture passed. These are not application/package dependencies and no
working environment was modified. The first full Laravel attempt hit the host CLI's
128 MiB limit; the complete rerun used
an explicit test-process 1 GiB limit, not a production configuration change.

Native Windows services/ACLs/PS5.1/.NET/locked-file behavior, Mac launchd/Keychain/service
user execution, Intel execution, clean offline installs, actual reboot/physical power loss,
client OS trust/firewall/DNS changes, physical cameras/readers and loaded hardware traffic
are **NOT RUN**. SIGKILL, adapter rollback and simulated disk/ACL/locking failures do not
certify those scenarios. No browser UI changed; no new browser/layout or model-inference
suite is claimed for D5B.

## Complete D5B changed-file list and additive schema

Compared with the captured D5B starting workspace, not earlier user/OCR/deployment edits.

Modified existing source:

```text
app/Console/Commands/BackupRestore.php
app/Console/Commands/BackupRun.php
app/Http/Controllers/Api/DeliveryIntegrationController.php
app/Providers/AppServiceProvider.php
app/Services/BackupService.php
app/Services/LifecycleBarrier.php
app/Services/SettingsService.php
build/macos/OFFLINE-INSTALL.md
build/macos/build.py
build/windows/OFFLINE-INSTALL.md
build/windows/assemble.ps1
build/windows/package.py
deploy/lifecycle/coordination.py
deploy/lifecycle/maintenance.py
deploy/lifecycle/service_host.py
deploy/windows/backup-now.ps1
deploy/windows/install.ps1
deploy/windows/lib/daily-backup.cmd
deploy/windows/lifecycle-adapter.ps1
deploy/windows/restore.ps1
tests/Feature/BackupRestoreTest.php
tests/deployment/test_package.py
```

Added source:

```text
DEPLOYMENT-D5B.md
app/Console/Commands/RecoveryPrepare.php
database/migrations/2026_11_20_000001_create_recovery_state.php
deploy/lifecycle/RECOVERY.md
deploy/lifecycle/backup_contract.py
deploy/lifecycle/recovery-check.php
deploy/lifecycle/recovery.py
deploy/lifecycle/recovery_guard.py
deploy/lifecycle/recovery_platform.py
deploy/lifecycle/recovery_security.py
deploy/macos/recovery.command
deploy/windows/lib/recovery-task.ps1
deploy/windows/recovery-acl.ps1
tests/fixtures/d5b-runtime.php
tests/recovery/fixture.py
tests/recovery/test_failures.py
tests/recovery/test_http.py
tests/recovery/test_recovery.py
tests/recovery/test_security.py
tests/windows/recovery.tests.ps1
```

`2026_11_20_000001_create_recovery_state.php` adds only a singleton epoch/active-set table;
its down path refuses silent removal of security state. It was applied **only to private
fresh fixtures**, never the working database. No other schema or data migration was added.
This is not an approved upgrade path for an existing D5A installation.

## Source, artifacts and upgrade scope

Base commit remains `d0e0d93d2a7193b956735c5c58a234b945d1a3f1`. Current D5B source is
uncommitted. Original archives were rehashed and preserved:

| Original candidate | Bytes | SHA-256 |
| --- | ---: | --- |
| Windows D2 `0.1.0-d2.1-dev.092593dd1048` | 576,413,086 | `4153ad831042640144f8909177de4bf0fd5dcafcb22b608121b42e67850552dc` |
| Mac arm64 D3 `0.1.0-d3.1-dev.66c18ba2ecda` | 497,589,601 | `01cdbcdf56d24d8ed843b401e3fba5f097411737be56aa2fb700dee797677a80` |
| Mac Intel D3 same version | 618,013,482 | `e92ea889aa1222ccb0b54bdf1a046c4cff0ea46821851f81e302bb668861de95` |

Their original source IDs remain Windows
`092593dd104894ad8d0236c292b3129eb3590e8998484248d47f11674041a4d5` and Mac
`66c18ba2ecda68cb7d3e4464d8e24cd54acbfb7dde44068e7845c4cf06148fc9` /
`D3-local-20261108`. They contain neither D4/D5A nor D5B and cannot be relabeled current or
combined into a same-source matrix. D5B code/migration/build inputs change schema/source
identity. Builders now ship the trusted D3 parser and recovery guide alongside recovery
code. Protected state is excluded from source/payload capture. A clean rebuilt three-platform
set and native acceptance remain required; **no new complete archive was built** in D5B.

Free space was checked before staging (about 15 GiB), with bounded fixtures and no removal
of user files/packages/backups. No live backup/restore/reset/migration, service/device/trust
change, workflow trigger, project commit/staging/push/publication/deployment occurred. Existing OCR
and prior deployment edits were preserved. The working DB was not used or restored; active
external application processes prevent an independent byte-for-byte DB-preservation claim.

Only **same exact synthetic D5B source/schema/runtime and installation** was restored, with
Windows and Mac layout adapters. No older production/D2/D3/D4/D5A-to-D5B upgrade, changed
runtime/schema version, cross-machine/platform or general downgrade origin is approved.
D3 `build/updates/compatibility.json` remains unchanged with no authorized origins. Do not
run the additive migration on a working Windows installation as an implicit D5B upgrade.

Private evidence pointer: `/tmp/philcst-deployment-d5b-workspace.txt`. The directory contains
`implementation-plan.txt`, D5A baseline/final logs, `recovery-final.log`, `native-recovery.log`,
`laravel-final.log` / `laravel-final.xml`, `d4-native-tls-complete.log` / `d4-tls-complete/results.json`, real HTTP and package/update/network/PowerShell logs,
`preserved-archives.json` and `preservation.json`. These are local test evidence, not working
installation backups, production secrets or publisher trust material.

## HANDOFF FOR D5C AND D5D

D5C must authenticate verifier/root/state provisioning, metadata freshness/replay/channel/
version compatibility, bounded optional discovery/download and USB import, then obtain
admin confirmation of a concrete update/backup/recovery plan. Reuse D5A fencing and D5B
coherent safety/journals, but certify trusted migrations against copies and new source/runtime
promotion separately. Include all writers, scheduler/self-task ownership, private TLS,
network receipts, APP_KEY, outbox, deny/revocation/account/epoch policy, startup/readiness,
fault recovery, native ACL/ownership and retention acceptance. No origin is implicitly
approved by the D5B fixture version. Signing/notarization and earlier dependency/license
release gates remain outstanding.

D5D must implement authenticated cross-PC ownership/epoch transfer and disaster recovery,
including latest monotonic deny/trust/security facts, private issuer and deliberately moved
canonical mapping/client enrollment. Prevent dual collectors/server owners. Define recovery
when newest protected state is unavailable; do not regenerate missing keys or import old OS
service/trust definitions. Keep ordinary same-installation restore, update and transfer as
separately authorized operations.

**Stop after D5B. Wait for “Go Deployment D5C.”**
