# Deployment D5D — Authenticated server transfer and disaster recovery

27 November 2026, Asia/Manila. D5D only. **Source implementation, synthetic
verification, native installed acceptance and deployable packages are separate
results.** No production transfer pair, Windows↔Mac native handoff or disaster
activation is certified. Stop after D5D; wait for **Go Deployment D6**.

## Implemented paths and prerequisites

D1/runtime inventory through D4, the D3 update amendment, D5A, D5B, D5C and actual
writer/delivery/recovery/update/network/service/package paths were inspected.
No applicable AGENTS.md was found. Existing uncommitted D5C implementation,
application/OCR/registration changes, databases and earlier packages were retained.
D5B remains same-installation recovery; D5C remains explicit same-host update.
Their identity checks were not relaxed to accept a foreign restore.

D5D adds a **separate elevated installed controller** with public-peer bootstrap/
enrollment, inspect/plan/prepare/export/import/promote/verify/activate, signed
receipt exchange, controlled pre-activation cancellation and explicit interrupted
operation recovery. Preparation works offline with a complete trusted locally
installed target package. It neither downloads a branch nor installs runtimes
from transfer media. An additional PC remains a browser client.

Concrete prerequisites are initialized D5A delivery and D5B recovery/host/security
state, an authenticated target package/native runtime, an independent **D5D-capable**
retained recovery capsule, empty target operational state, protected distinct
transfer keys and an exact reviewed source/target/SQL compatibility rule. An old
D5C-only capsule cannot enforce retirement and is refused. Unknown roots, network
share SQLite, conflicting operational target records or missing protected state
are refused. The target must contain only its non-account defaults; accounts,
vehicles, tags, occurrences and deliveries are never merged with another database.

Production `deploy/transfer/compatibility.json` is deliberately empty. D2/D3 unsigned
archives and pre-D5A/B installations need separately authorized adoption; this
batch does not claim that source code makes them transferable today.

**Planned transfer** is implemented and exercised using two distinct synthetic
host identities. **Disaster recovery** has a separately labeled read-only recovery-
point inspection and explicit activation blockers. No independently held disaster
recovery authority/signature policy, latest security escrow or institutional
older-state loss authorization was supplied. Disaster activation stays blocked;
this does not bypass ordinary host binding or regenerate keys.

## Authentication and versioned state

Transfer authority uses a separate RSA key (minimum 2048 bits) and the established
PHP OpenSSL RSA-PKCS1-SHA256 mechanism over canonical signed JSON. The protected
native signer reads its local key; verification uses only independently enrolled
public SPKI material. TLS issuer and software publisher keys cannot be reused as
the local transfer key. No fixture/private publisher/transfer key is distributed.

Each peer enrollment binds public-key fingerprint, host identity, root identity
and fixture/production environment. Public-key hashes and host/root identities
must be exchanged independently by administrators. A key carried in an archive is
not authority. Unknown keys, signature tampering, wrong purpose/target, expired
receipts, malformed fields and reused challenges are refused. Seven-day metadata
freshness requires an independently checked clock; protected software-update clock
floors are preserved and a backward target clock is refused.

| Contract/state | Purpose |
| --- | --- |
| `philcst.transfer-challenge.v1` | One-time UUID/nonce, intended source key, target key/host/root/installation, native binding and actual SQL schema |
| `philcst.transfer-plan.v1` | Exact target challenge hash, source host/package/SQL/security/owner, target network/TLS/custody decisions and named approval hash |
| `philcst.transfer-manifest.v1` | Source-signed retirement, intended target/challenge, source/target source/schema/runtime identities, recovery point, full archive/receipt/manifest hashes, retained delivery/ACK summary and approval |
| `philcst.transfer-isolation.v1` | Target-signed named administrative isolation/address-conflict attestation for the exact signed manifest |
| `philcst.transfer-activation.v1` / `rejection.v1` | Target-signed activation/nonce consumption or permanently cancelled target receipt |
| `philcst.transfer-control.v1` | Protected local host/root, separately held authority/peers, operation phase, journals, used challenges, import/proof/move/activation receipts |
| `lifecycle/ownership.json`, schema 1 | Protected deployment UUID, distinct local owner UUID, ownership epoch and active/retired/reserved/cancelled state |
| `philcst.transfer-paths.v1` | Exact source binding → target binding plus actual SQL schema; production allowlist remains empty |

TLS issuer comparison reads its public certificate and supports EC or RSA issuer
keys without weakening the separate RSA transfer-authority requirement. An isolated
EC-issuer bootstrap regression reproduced the original failure before this fix.
No TLS private key needs to be opened merely to compare authority identities.

The authoritative semantic fields/purpose checks are in
[contract.py](deploy/transfer/contract.py), orchestration in
[control.py](deploy/transfer/control.py). `TRANSFER.json` is an external signed
handoff envelope alongside the D5B `backup.zip` and `receipt.json`. It is not an
update manifest or the payload's content inventory. Every underlying payload byte,
original operation/media/ACK and source recovery point is bound through those
signed hashes; checksums alone are not sender authentication.

Private state lives under `ROOT/state/transfer` on Windows or
`ROOT/shared/transfer` on Mac. An exclusive persisted PID owner serializes local
handoffs; dead owners need explicit review. A source cannot prepare another target
while its existing transfer is unresolved. A challenge binds one target key,
machine, root and initial installation; it cannot be consumed on another target.
Cancellation permanently consumes that target challenge. No automatic failover,
active-active cloning, remote lease service or database synchronization is added.

## Planned handoff and journal sequence

1. Verify the target's installed native code/runtime inventory, readiness imports,
   source/schema/runtime pair, disk, empty operational DB/outbox and local root.
   Register both transfer authorities independently.
2. Reserve/fence the target and sign its source-key-bound one-time challenge.
3. Source plan binds the challenge, current actual SQL/security/owner, complete
   package identities, target network/TLS decision, protected-media custody and
   named local administrator confirmation. Revalidation after quiescence must
   agree with the approved security decision.
4. D5A closes source admission, finishes producer work, stops/drains owned services/
   tasks and fences all writers. D5B capture/validation/publishing creates a coherent
   source safety set with pending work retained. A valid pending queue is not
   mislabeled drained or discarded.
5. Persist **source retirement before any signed transferable export**. The source
   remains retired even when the target's later acknowledgement is missing.
6. On the intended target, validate signer/challenge/retirement/source/target policy
   and hashes, copy into protected local staging, revalidate, then extract safely.
   No unverified incoming executable/helper is run.
7. Validate source data with source path/type conventions while keeping crypto/
   recovery tools bound to the installed target. Regenerate target environment,
   path/cache/native configuration and permissions. Source Windows executables,
   launchd/services/SIDs/accounts/firewall/hosts/OS trust are not imported.
8. Reconcile monotonic security and create a new local recovery epoch/set; preserve
   the source installation/database generation, original envelopes and receipts.
   Stage per-role old/new hashes and target-owned configuration before promotion.
9. Promote under the owned D5A writer reservation with durable per-component intent,
   retained original target files and hash-checked retries. Re-capture/verify keys,
   DB/relationships/media/generation/trust and target host receipt while fenced.
10. Require a separate named isolation/address-conflict attestation and exact manifest
    confirmation. Persist activation intent before reopening access. Verify target
    network/issuer and application/pinned HTTPS readiness, then reopen collectors.
    Device readiness is separate; no successful detection is fabricated.
11. Return the target-signed activation receipt to the already retired source.

Only TLS **preservation** is implemented in this transfer. A missing/suspect issuer
cannot be silently replaced. Issuer rotation requires a separately verified root
and client enrollment procedure and is refused in the transfer decision contract.
The copied private issuer gets target protections; source OS enrollment receipts
are not claimed to enroll target Keychain/Windows trust.

The target uses its already configured D4 mode, real NIC/address and exposure
receipts. Transfer regenerates Caddy/application paths and relay/cache inputs;
it does not assign an address, rename the computer, alter school DNS/router or
import source firewall configuration. A changed target configuration requires
renewed approval. Native isolation, duplicate-address and exposure acceptance
remain required; an attestation is not a remote physical-state sensor.

## Actual ownership guarantee and limits

The ownership receipt is outside ordinary promoted DB/data sets. Its required flag
makes a missing receipt fail closed. Python producer admission/outbox transport,
PHP writer/ingestion identity checks, scheduler/service startup gates, maintenance
Enter/Resume, ordinary D5B restore/recovery and D5C plans honor the receipt. Retired,
reserved, cancelled or incomplete owners cannot resume through ordinary tools.
D5C also refuses a future target package that drops required D5D ownership controls.

Integration retains its API-key, exact installation/generation/checksum and receipt
rules. Managed transport adds `X-Installation-Owner` from the current protected
owner. The payload is not rewritten to add that header. Old/missing owner headers
are rejected once D5D ownership is enabled. Retained source-generation evidence
stays explicitly admitted as the same transferred installation/generation and
exact immutable operation/media/receipt set on the new owner; no global generation
validation bypass is introduced. New target capture continues that generation
under the new ownership token/epoch. Integration credentials are preserved.

These are **local managed-workflow controls against accidental dual activation**,
not a remotely enforceable distributed lock. A local epoch cannot fence another
computer, devices do not validate this token, and old software may ignore local
receipts. Therefore verified physical or administrative network isolation of the
source is mandatory before target activation. Timeout, no ping or an unreachable
source is never proof. Same-address handover needs that isolation before target
address use plus explicit conflict inspection. A privileged administrator restoring
arbitrary old machine snapshots, copying protected keys/receipts or running old
software outside this workflow is outside the guarantee. No automatic failover
or protection against such snapshot resurrection is claimed.

## Preserved data/security and honest disaster limits

DB accounts/roles/passwords/completed setup, staff/defaults, vehicle registrations
including untagged vehicles, leading-zero lost/unavailable UIDs, stable gates/history,
assignments/calibration, exact occurrences/chronology/occupancy, snapshots/crops,
APP_KEY and decryptable camera credentials remain. Pending outbox original evidence,
receipt/ACK identities, recovery security/deny epochs, update trust/generations/
revocations/sequence and clock floors and the TLS issuer/root survive. No history
is guessed, renumbered or replayed because a host changes.

Session versions increase; sessions, remember/activation/reset/setup codes and
stale browser/update requests are invalidated using the existing D5B security
mechanism. Completed setup never reopens. Target-specific prepared update caches/
requests are invalidated without changing publisher trust or widening platform key
scope. A publisher key not authorized for a new architecture remains unable to
sign updates there until separately authorized rotation; transfer grants no scope.

Source safety/recovery sets and original installation are retained. The export
contains private keys and secrets and is **not encrypted by this tool**. Export
requires explicit approved-encrypted-volume custody and restricted permissions;
that argument records an administrator assertion, not an encryption detector.
Use institutionally approved BitLocker/APFS encryption, separate recovery-key
custody and protected media handling. No backup/user files were deleted for space.

Disaster inspection considers up to 100 supplied coherent sets and selects the
newest declared recovery point, reporting retained pending work and invalid sets.
Its checksum/declared timestamp selection does **not** prove authentic sender,
newest actual state or absence of later movements/revocations. It does not mutate
active data or activate. Missing independently held recovery authority/signature,
newer monotonic security material or approved older-state loss policy and verified
source isolation are explicit blockers. Missing APP_KEY cannot be repaired with
another key; missing issuer/private material cannot be replaced silently. No
lossless disaster recovery or merging unrelated later databases is claimed.

## Cancellation, failure and recovery

Before target activation intent, target can sign a permanent cancellation/nonce-
consumption receipt. Only that verified rejection lets the source cancel its exact
handoff and resume after normal checks. A mere missing acknowledgement cannot
cancel a potentially active target. After target activation intent or new traffic,
cancellation/ordinary source resume is refused. Returning to the source requires
another coherent authenticated handoff to a fresh target, not blind rollback.

Source capture/retirement/export, protected target extraction/staging, per-component
promotion, verification, isolation and activation are journaled. Dead-owner review
never reopens writers. `recover-export` completes the captured exact retirement/
export intent; `recover-import` re-extracts only reverified protected source bytes
before promotion. `promote` resumes known old/new hash transitions; unknown/missing
bytes keep the target fenced. An activation interrupted before readiness can be
recovered without reviving the source. After first target startup, signed receipt
recovery does not overwrite later target activity. Never restart in `finally`.

Source key/host binding is checked separately from target registration, so D5B
same-host checks stay intact. The retained local target D5D code/interpreter is the
recovery tool; archive-supplied scripts/OS definitions never become authority.

## Commands and client reconnection

Complete Windows PowerShell 5.1/Mac sudo commands, trust bootstrap, plan/confirmation,
protected-media export/import, activation/recovery/cancellation and disaster
inspection are in [TRANSFER.md](deploy/transfer/TRANSFER.md). Both use the protected
D5D-capable capsule named by `state|shared/update-controller.json`.

The canonical origin remains **https://philcst.vms.internal**. Independently verify
the new address and preserved public-root fingerprint. Client wrappers require
old-server isolation and compare the old recorded address/root before invoking
existing D4 Connect. They validate pinned TLS before replacing only owned mappings;
stale/unowned/IPv6/DNS/root conflicts are refused. DNS is changed only by the school
administrator. Root rotation needs separately verified Remove/re-enrollment, not
automatic trust replacement. Borrowed/unrelated roots and hosts remain. Clients
sign in again and refresh deliberately; the existing D5C unsaved-input/version
notice remains. They install no collector/runtime/database.

Windows:

```powershell
.\Reconnect-to-PHILCST.ps1 -OldServerAddress 192.168.44.10 -ServerAddress 192.168.44.20 -RootCertificate E:\PHILCST-public-root.crt -RootFingerprint INDEPENDENT_SHA256 -OldServerIsolated
```

Mac:

```sh
sudo ./Reconnect-to-PHILCST.command 192.168.44.10 192.168.44.20 /Volumes/USB/PHILCST-public-root.crt INDEPENDENT_SHA256 --old-server-isolated
```

## Exact tested compatibility and verification

Synthetic hosts use separate independently enrolled RSA authorities, host/root/
installation identities, real isolated Laravel DBs/media/certificates and owned
OS adapters. Source/target metadata is the test-only `1.0.0`, source hash `bbbb…`
(64), commit `aaaa…` (40), build identity `SYNTHETIC-D5B`, the exact current migration
map/actual SQL schema and per-platform fixture runtime fingerprint. These are
explicit synthetic markers, not production hashes falsely attributed to binaries.

The fixture allowlist contains only its constructed exact source→target pair.
Same-source Mac→Mac, Windows-layout→Mac and Mac→Windows-layout are exercised using
the target's own app/runtime/configuration paths. Windows-layout executables in
these tests are **Mac host PHP fixtures named for that layout**, not Windows binary
execution. Bundled arm64 Python 3.12.16 executes the final D5D suite with its actual
requests library; PHP uses host 8.5.3. No Windows↔Mac or Intel production path is
certified. Intel layout/native code exists but actual Intel execution is NOT RUN.
Production compatibility remains empty; unknown runtime/version/SQL pairs fail.

| Check | Executed result |
| --- | --- |
| Complete D5D transfer/client suite after the last implementation change | **19 passed**, bundled native arm64 Python 3.12.16/requests and host PHP 8.5.3; includes six SIGKILL subpoints, distinct hosts, exact target binding, retirement/cancellation/replay, actual PHP/Python retained delivery, security/media/decryption, Windows/Mac layout regeneration, EC issuer and client policy |
| Complete affected D5A/B/C final reruns | **D5A 31 / D5B 28 / D5C 30 passed**, complete suites after the last implementation change; exact-pair approvals remain fixture-only |
| Full isolated Laravel | **898 tests, 9,279 assertions, 1 existing skip**, no failures; the skip is the unavailable detector development venv, not waived transfer/native acceptance |
| D3 update contracts / D2 package / Mac package policy | **29 / 14 / 19 passed** |
| Network policy / stream fixtures | **17 passed**, private adapters/loopback; no OS network/trust mutation |
| Windows pure common/preflight/lifecycle/recovery/client checks | **17 / 13 / 30 / 9 / 22 passed**, macOS PowerShell 7; **37 scripts parsed**, zero syntax errors; not PS5.1 native acceptance |
| JavaScript unit tests | **17 passed** |
| Isolated Chrome | Updates DOM **13**, rendered Laravel Updates desktop/390px **12**, Registry status/owner **83**, list reports **64** checks passed |
| Frontend | Private-copy Vite production build passed |
| Syntax / boundaries | 19 Python files AST-parsed; five PHP files, reconnect shell syntax and `git diff --check` passed |
| Client-only ZIPs | Both archives integrity-checked; helper inventories verified, hashes below |
| Preservation | Working .env/staged index/827 unrelated source hashes unchanged; original D2/D3 archive hashes unchanged; no live DB fixture use |

An earlier oversubscribed updater run exceeded the unchanged 120-second child
limit at two SIGKILL subpoints. Its complete final rerun uses two workers, with
no timeout/assertion changes. The four-boundary updater SIGKILL case then passed.
The retained-delivery kernel fixture was also corrected to use the real
`127.0.0.1:8080` integration origin after named HTTPS was relocated; production
origin checks were not relaxed. The separate high-port HTTP fixture explicitly
uses its isolated development origin and verifies real Python requests/ACK and
Laravel ingestion, not OS/browser TLS trust.

All tests use private fixtures. No working DB/keys were copied for testing and no
physical camera/reader was opened. Failure injection uses synthetic OS adapters;
SIGKILL observes process boundaries, not filesystem power-loss durability. Full
native installed acceptance remains unverified.

Native Windows/PS5.1/SCM/tasks/SID/ACL/locked-file behavior; Intel executable imports;
privileged Mac launchd/service-user/Keychain/ALF behavior; clean machine/no-development-
tools/WAN-unplugged install; actual two-machine transfer; reboot/physical power
loss; duplicate-address detection on a real NIC; client OS trust/mapping changes;
physical cameras/RFID/video load; production signing/notarization and institutional
redistribution/dependency decisions are **NOT RUN / unresolved**. No model inference
or hardware acceptance is inferred from PHP/Python fixtures. Existing classification-
UI fixture mismatch remains outside D5D; selected browser regressions are separate.

## Complete changed files, schema/configuration and preservation

D5D adds no application migration and runs none against the working installation.
New protected filesystem state is transfer authority/peer/control/journals and the
local ownership receipt/required flag. Target recovery state/DB security epoch is
advanced only inside isolated fixtures here. No D5C production origin/transfer
allowlist is populated. TLS trust, update trust and transfer authority remain
separate. The target's native service/NIC/exposure policy is retained/regenerated;
source OS definitions are not imported.

```text
DEPLOYMENT-D5D.md
app/Http/Middleware/LifecycleWriterBarrier.php
app/Services/LifecycleBarrier.php
build/clients/build.py
build/macos/build.py
build/windows/assemble.ps1
build/windows/package.py
deploy/clients/CLIENT-CONNECTION.md
deploy/clients/Reconnect-to-PHILCST.command
deploy/clients/Reconnect-to-PHILCST.ps1
deploy/lifecycle/coordination.py
deploy/lifecycle/maintenance.py
deploy/lifecycle/ownership_guard.py
deploy/lifecycle/recovery.py
deploy/lifecycle/service_host.py
deploy/transfer/TRANSFER.md
deploy/transfer/cli.py
deploy/transfer/compatibility.json
deploy/transfer/contract.py
deploy/transfer/control.py
deploy/transfer/crypto.php
deploy/transfer/native.py
deploy/transfer/updates_native_import.py
deploy/updates/install.py
deploy/windows/transfer-preflight.ps1
school-vehicle-monitoring-detector/__pycache__/laravel_client.cpython-314.pyc
school-vehicle-monitoring-detector/durable_delivery.py
school-vehicle-monitoring-detector/laravel_client.py
tests/Feature/DurableDeliveryTest.php
tests/fixtures/d5d-runtime.php
tests/transfer/fixture.py
tests/transfer/test_clients.py
tests/transfer/test_transfer.py
```

Preservation is checked relative to the **already dirty D5C starting workspace**,
not attributed from the entire Git diff. The working .env, staged index and 827 prior source-file hash checks pass. A preexisting tracked Python
bytecode cache changed while development device/camera services were running; it
is listed as a generated runtime change, not an authored source edit. Those services
were not used as test targets, stopped, reconfigured or repaired by this task.
The working DB/cache was never restored to force a preservation claim. No commits/staging/pushes/workflow triggers,
publication, deployment, live restore/update, device/service/OS trust/DNS/router or
live database mutation were performed. Existing active services may independently
write their working DB, so byte-for-byte live DB preservation is not asserted.

## Artifacts and release blockers

No new complete Windows/Intel/arm64 server archive was built. Existing D2/D3
archives remain original old candidates and do not contain D4/D5A–D5D. Full native
Windows assembly/acceptance is unavailable, reviewed production signing/transfer
paths are absent and the original multi-GiB build cache is unavailable. Disk was
checked before fixture copies/builds (about 14 GiB initially; later about 16 GiB);
no previous packages/backups/user files were deleted. D6 must rebuild all three
platforms from one reviewed clean commit/version and certify those exact artifacts.

Preserved older complete archive identities (rehashed during D5D):

| Older candidate | Bytes | SHA-256 |
| --- | ---: | --- |
| `build/windows/dist/D2-Windows-x64/PHILCST-VMS-0.1.0-d2.1-dev.092593dd1048-win64.zip` | 576413086 | `4153ad831042640144f8909177de4bf0fd5dcafcb22b608121b42e67850552dc` |
| `build/macos/dist/Mac-Intel/PHILCST-VMS-0.1.0-d3.1-dev.66c18ba2ecda-macos-x86_64.tar.gz` | 618013482 | `e92ea889aa1222ccb0b54bdf1a046c4cff0ea46821851f81e302bb668861de95` |
| `build/macos/dist/Mac-arm64/PHILCST-VMS-0.1.0-d3.1-dev.66c18ba2ecda-macos-arm64.tar.gz` | 497589601 | `01cdbcdf56d24d8ed843b401e3fba5f097411737be56aa2fb700dee797677a80` |

Builders now include the D5D code and TRANSFER.md. Small updated **client-only**
reconnection candidates were built and every ZIP member rehashed. They contain no
server state, CA/private key, runtime or database. They are unsigned development
helpers, not authenticated releases or installed acceptance.

| Client-only artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `build/clients/dist/D5D-final/Connect-to-PHILCST-Windows-D5D-dev.123d8bd48e98.zip` | 11393 | `4bcbe31c94b5da5f09df6412006b0e72147a48c5fd91c8aa2a6e50170121d199` |
| `build/clients/dist/D5D-final/Connect-to-PHILCST-Mac-D5D-dev.123d8bd48e98.zip` | 9575 | `ad4b895a4497836464850f227b0c4aaf25525a5fc7927949b3a005d54f88b1e7` |

Common helper-source SHA-256: `123d8bd48e98d39f0f7a0914fb3488e69f76bab4ef581a88ca553d3bfeee1c8c`. Checksum sidecars use each ZIP path plus `.sha256`.

The shared helper source hash identifies only these helper files, not the complete
application or a same-source server release matrix. Source remains an uncommitted
development working snapshot based on `c4d5e07c0bc1d879ec93742802a6c51693ecfc22`.
No old archive/source identity was rewritten or relabeled as D5D-ready.

## HANDOFF FOR DEPLOYMENT D6

- Review exact source/target compatibility including native runtime/SQL and any
  independently authorized legacy adoption or preceding D5C upgrade. Keep unknown
  origins blocked; do not generalize these fixture markers into production ranges.
- Rebuild Windows x64, Intel and arm64 from one approved clean source/version and
  authenticated complete package set; close signing/notarization, Intel/PHP
  maintenance and redistribution/source-notice gates from D3/D4.
- Certify native bootstrap/capsule retirement guards, local-disk/ownership/ACLs,
  service/task stop/restart, actual owner-header transport and one-owner behavior
  on two physically distinct disconnected targets. Verify the source cannot reopen
  through ordinary resume, recovery, update or reboot.
- Test real NIC duplicate-address/isolation, D4 canonical DNS/owned mappings, preserved
  or independently approved rotated issuer and native Windows/Mac client trust.
  Never infer remote fencing from local epochs, no ping or a fixture assertion.
- Test crashes/power loss at source retirement/export, extraction, keys/data/ownership
  promotion, first target startup, receipt return and client-name transition.
  Recovery after target traffic must preserve that newer traffic and cannot blindly
  revive the source. Retain safety material until institutional acceptance.
- Establish independently held disaster-authority/key recovery, authentic signed
  recovery points, current security/revocation escrow and explicit recovery-point
  loss policy. Until supplied and tested, disaster activation remains blocked.
- Run complete application/plate/OCR/optional-RFID/security/media/ownership and
  hardware acceptance against actual final hashes. Keep browser clients passive;
  one main server owns devices, DB, durable evidence and canonical reconciliation.

Final evidence logs: `transfer-complete-final.log`, `updater-complete-final.log`,
`d5a-complete-final.log`, `d5b-complete-final.log`, `laravel-final.xml`,
`powershell-final.log`, `syntax-final.log` and `preservation-final.json`.

Private evidence pointer: `/tmp/philcst-deployment-d5d-workspace.txt`. Evidence logs,
JUnit and synthetic state are not installation backups or production trust roots.

**D5D stops here. Wait for “Go Deployment D6.”**
