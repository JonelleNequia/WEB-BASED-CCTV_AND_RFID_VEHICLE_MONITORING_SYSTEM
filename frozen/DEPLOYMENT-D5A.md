# Deployment D5A — Durable delivery and maintenance controls

Source and isolated-fixture implementation: **9 November 2026, Asia/Manila**.
Authorized scope is D5A only. This is a development candidate, not a deployed or
native-certified installation. **Stop before D5B.** D5B backup/restore, D5C installed
updates and D5D transfer are not implemented by this batch.

The D5A attachment, applicable-instruction search, D1/runtime inventory, D2,
complete D3/update amendment, D4 and actual delivery/service/scheduler/configuration
paths informed this implementation. No applicable AGENTS.md was found. Earlier
application/packaging edits remain. Tests used private source copies, synthetic
records, local databases/media/keys and service adapters. No live migration,
device/service/configuration change, reset, deployment, commit, staging, push,
publication or production update execution was performed.

## 1. Writer inventory and verified previous failure paths

| Actual path | Previous boundary/problem | D5A maintenance behavior and state |
| --- | --- | --- |
| `detector_service.py` → `LaravelEventClient` crossing/event/guest submissions | HTTP completion and volatile per-pass state were not a durable acknowledgement; a lost response could leave uncertainty | Persist immutable operations/media before transport; registered submission work finishes persistence before owned producer stop |
| Later OCR, plate crop and clearer-picture revisions | A later submission must retain its original occurrence/camera; current configuration cannot reconstruct it | Separate content/revision identities and dependencies; original camera ID retained in both late-evidence paths; no current-gate inference |
| `devices/reader_link.py` TagPoster | Development thread queue can fill/drop and has no restart persistence | Initialized installations synchronously persist accepted scans, then use the durable worker; original `scan_time`, UID and reader context retained |
| `device_service.py` scan-result posting | Pending transport retry was volatile | Durable device-result operation with original completion time; retained pending result, separate from RFID scans |
| Browser/manual/Review/Registry/accounts/settings/media requests | Request-start flag alone cannot fence an already-running transaction; GET middleware can also reconcile RFID/write sessions/cache | Outer writer reservation spans the complete application request; existing permissions and business rules remain; operational requests receive retryable maintenance responses |
| `FinalizePendingScans` | Running before receipt lookup could make a verified retry perform unrelated reconciliation | Durable requests finalize inside the first operation's database transaction, after receipt lookup; verified retries do not finalize again |
| Scheduler callbacks, Artisan commands and synchronous jobs | Scheduler could restart collectors and write cache mutexes; standalone commands could bypass web maintenance | Console reservation includes scheduler/mutex work; callbacks also use the shared barrier; installed tools discover the configured lifecycle root; packaged queue remains synchronous, with no separate queue worker |
| Runtime/device configuration and status files | Browser/settings, device discovery and collectors can write different state files | Application writers are fenced; owned producer/scheduler processes are stopped and checked before quiescence; runtime/status files remain outside immutable code |
| NSSM, scheduled tasks, launchd and standalone collectors | Service restart/reboot could reopen writers during an interrupted operation | Persisted-phase startup gates park children before writer startup; capture does not start during `resuming`; only controlled access roles may start then |
| Legacy backup/reset/restore/update/uninstall/reinstall | Old operations do not preserve a coherent DB/media/keys/outbox cut; adding a flag would not certify them | Explicit refusals in PHP services/CLI and platform entry points for initialized D5A installations; old Stop shortcuts refuse in favor of owned maintenance; Windows daily backup task disabled until D5B |
| D4 local/LAN mode change | Web/PHP/relay reload interrupted integration without durable delivery/fencing | Uses the same owned Enter/Resume orchestration; existing config rollback remains inside the controlled prepare step |
| Explicit D4 server trust enrollment | An administrator can write OS trust and its ownership receipt | Enrollment uses the operational reservation and refuses while fenced; public-root export remains a read of the unchanged issuer into an external file |

Development without `PHILCST_LIFECYCLE_ROOT` keeps its existing behavior. This batch
does not silently enable new persistence or schema against the working installation.
Fresh D5A package provisioning initializes it explicitly after fresh migrations.

## 2. Delivery identities, acknowledgement and retry

`school-vehicle-monitoring-detector/durable_delivery.py` uses bundled Python
`sqlite3`, standard-library encoding/hashing and the already bundled requests.
There is no new package dependency. Each schema-1 envelope retains installation
UUID, database-generation UUID, kind, stable occurrence, operation ID, original
payload and media inventory. The operation SHA binds the exact serialized envelope.

Crossings/late evidence retain their existing external occurrence key. RFID and
device results derive a stable capture identity from the original payload/time;
new physical scans with different capture timestamps remain distinct. Different
kinds, payload revisions and evidence bytes produce separate operation identities.
Retries reuse the stored envelope and original timestamps/direction/gate/camera/
reader metadata. They do not recapture images or rewrite old context.

Dependencies preserve initial-before-later evidence and enqueue ordering within a
gate lane, including RFID/crossing work. A dead initial operation blocks dependent
work; other lanes can proceed. Existing chronological occupancy/reconciliation and
eligibility/context validation still decide canonical effects.

`POST /api/v1/integration/deliveries` requires the existing API key. The new
`DeliveryIntegrationController` validates the immutable checksum, identity and
evidence before dispatching existing ingestion controllers. Receipt insertion and
canonical database effects share one transaction. A write reservation precedes
receipt lookup, including SQLite's deferred-transaction case. A matching prior
receipt is verified before current gate/camera validation; this confirms an already
accepted operation without replaying it. New work still fails if its original gate
is archived or its camera context is no longer valid.

An acceptance acknowledgement is exactly bound to:

```json
{"delivery_ack":{"schema":1,"operation":"64_HEX_OPERATION","sha256":"64_HEX_ENVELOPE_SHA","installation":"INSTALLATION_UUID","generation":"DATABASE_GENERATION_UUID","committed":true}}
```

The response also distinguishes `ack_effect: committed` from `verified` and carries
the existing ingestion result. A verified retry has `created: false`. HTTP 200 alone,
wrong/missing receipt fields, a lost response or malformed response do not release
queued evidence. No general exactly-once transport guarantee is claimed: the tested
contract is durable retry plus idempotent canonical effects.

States are `pending`, leased `inflight`, `acked` and visible `dead`. Connect/read
timeouts are 3/12 seconds; claims have a 30-second lease and unique attempt token.
Expired claims retry the same operation. A stale worker cannot overwrite a newer
acknowledgement. Backoff, attempt times/counts and next retry persist; waits are
bounded to 300 seconds, including bounded numeric/date Retry-After handling.
408/429, maintenance, transport and server failures retry. Other 4xx conflicts and
the 20-attempt budget retain work as dead. Explicit reviewed retry keeps the same
content/ID and preserves lifetime attempt/review counters.

## 3. Protected storage, evidence, capacity and recovery limits

```text
Windows ROOT/state/lifecycle/       Mac ROOT/shared/lifecycle/
  identity.json                    protected installation + generation UUIDs
  maintenance.json                 owned phase/build/secret-identity binding + receipts
  operation.lock                   exclusive maintenance owner/PID, when held
  coordination/barrier.sqlite      cross-runtime reservation + producer registrations
  delivery/outbox.sqlite           immutable envelopes, original media, retries and ACKs
  corresponding SQLite journals    preserve if present; do not copy a live database alone
```

These are local files outside releases. The application database stays local as
well. Neither identity nor a missing spool/barrier is silently recreated. Database
marker, protected identity and spool marker must agree before replay. Generation
is an interface for later certified recovery/transfer; D5A does not advance it or
claim cross-computer ownership fencing.

Original media is base64 encoded **inside the same SQLite transaction** as the
operation, with byte length/SHA-256 checks. There is no valid-looking committed
queue record pointing at a partially written external image. SQLite FULL synchronous
transactions and rollback recovery protect the tested process-termination boundary;
physical power-loss durability depends on the filesystem/hardware and is NOT RUN.
See [SQLite transactions](https://www.sqlite.org/lang_transaction.html) and
[synchronous semantics](https://www.sqlite.org/pragma.html#pragma_synchronous).

Expired worker leases are reconciled without deleting pending media. Malformed
retained bytes become visible dead work rather than being sent or stripped into a
different payload. Acknowledged envelope/media bytes are released only after the
matching committed receipt. Roughly 1,000 bounded acknowledgement records are kept;
oversized diagnostic results are reduced to the receipt/status and an application
details pointer. Full canonical receipts remain in the application DB.

Defaults: 5,000 unacknowledged operations, 512 MiB encoded retained work, a 32 MiB
SQLite metadata allowance enforced on reopened connections, and 256 MiB minimum
free disk. Capacity refusal does not evict pending/dead records. Capture pauses or
reports refusal; retained failures still consume capacity. Status includes counts,
oldest age, retries, failures, dependency blocks, allocated/retained bytes, free disk
and capture/delivery readiness. Existing detector/device status includes it. Secrets,
request URLs and arbitrary payload text are omitted from diagnostics.

The server fsyncs new private crossing images before attachment and tracks newly
allocated files for ordinary transaction rollback cleanup. Existing media is not
deleted by this cleanup. A process crash can still leave an **unreferenced server
file**, which remains unavailable through authenticated exact-reference serving;
the durable queue retains original bytes for retry. D5B must reconcile such files
against a coherent cut. Device configuration caches/files are not claimed to be a
single filesystem/database transaction; D5B must validate them too.

Inspect/export/retry procedures are in [MAINTENANCE.md](deploy/lifecycle/MAINTENANCE.md).
Export verifies original bytes into a new private folder and writes its review
manifest last, without changing the queue. Authentication can be corrected and
retried under review. Archived/reassigned context conflicts may require a separately
authorized historical procedure; D5A never remaps them or deletes them to unblock
a lane. Successfully persisted work is protected, not vehicles never captured during
shutdown, hardware failure, a full-spool refusal or an intentional pause.

## 4. Maintenance phases, actual writer barrier and controlled resume

The administrator creates/retains an operation UUID. An exclusive hard-linked owner
file prevents competing orchestration; an interrupted lock is never automatically
stolen. Persisted phases are:

```text
running -> quiescing -> fenced -> quiescent | quiescent_pending
                                      -> resuming -> running
any failed transition -> failed, with admission closed
```

Enter closes new operational admission, waits for registered submission work to
persist/finish, stops owned capture/scheduler producers, drains where feasible,
reserves the shared writer barrier, stops remaining writers and verifies services,
tasks and owned processes. The barrier is a separate local SQLite `BEGIN IMMEDIATE`
reservation held by PHP through request/command completion and by Python through
critical state changes. Therefore maintenance waits for the transaction already
in flight rather than relying only on a request-start flag. GET requests also take
the reservation because sessions/cache/reconciliation can write. `/up` remains a
narrow health check. This conservative serialization needs native load acceptance.

Producer grace defaults to 30 seconds; transport drain defaults to 10, both selectable
up to 60 seconds. Individual transport/OS/readiness operations have their own bounded
waits. Grace expiry leaves admission-only `quiescing`, with no intentional producer
kill: allow work to finish and repeat Enter with the same owner, or explicitly Resume
after checks. A still-unsubmitted detection window is not converted into a fabricated
movement to flush maintenance.

During `quiescing`, only authenticated durable delivery may drain; operational browser
and legacy integration paths receive 503/Retry-After. Invalid integration credentials
still receive 401. Once fenced, delivery also receives 503. No permission or context
rule is relaxed. `quiescent_pending` explicitly preserves pending/inflight/dead/blocked
operations; it is not a successful empty drain. Verify checks the owned stopped state.

Resume requires the same operation, intact coordination/spool SQLite integrity,
matching app/spool/database generation, source/version/architecture binding, retained
APP_KEY/API-secret identity and D4 issuer/readiness checks. Access roles start first;
HTTP/pinned HTTPS readiness must pass before capture/tasks reopen. Failure returns
to `failed` and attempts to stop owned services; it never clears missing state by
regenerating it. Startup gates persist across supervisor/reboot attempts.

`recover` validates that the exact recorded owner PID is gone and only removes its
owned lock; admission remains closed. Uncertain ownership/PID reuse refuses recovery.
`review-dead-producers` removes only definitely dead registered jobs and records that
review; live jobs and all queued evidence remain. Neither command is a recovery engine
for a corrupt/missing ownership file. D5A does not certify crash-safe promotion or
rollback. A completed Verify response is not a permanent lock for an independent copier.

## 5. Windows/Mac adapters, D4 integration and commands

Use a newly built, externally verified D5A candidate on a disposable authorized target.
**Do not apply source or run this migration against the working school installation.**
Existing D2/D3 archives cannot run these commands. Full commands/review procedures are
in the package's MAINTENANCE.md.

Windows, elevated PowerShell, bundled Python:

```powershell
$root='C:\PHILCST-VMS'
$py="$root\runtime\python\python.exe"
$ctl="$root\app\deploy\lifecycle\maintenance.py"
$operation=[guid]::NewGuid().ToString()
& $py -B $ctl status --root $root
& $py -B $ctl enter --root $root --operation $operation --producer-wait-seconds 30 --drain-seconds 10
& $py -B $ctl verify --root $root --operation $operation
& $py -B $ctl resume --root $root --operation $operation
```

Mac, corresponding native bundled runtime:

```sh
ROOT='/Library/Application Support/PHILCST-VMS'
PY="$ROOT/current/runtime/python/bin/python3"
CTL="$ROOT/current/app/deploy/lifecycle/maintenance.py"
OPERATION=$(uuidgen)
sudo "$PY" -B "$CTL" status --root "$ROOT"
sudo "$PY" -B "$CTL" enter --root "$ROOT" --operation "$OPERATION" --producer-wait-seconds 30 --drain-seconds 10
sudo "$PY" -B "$CTL" verify --root "$ROOT" --operation "$OPERATION"
sudo "$PY" -B "$CTL" resume --root "$ROOT" --operation "$OPERATION"
```

Windows uses a PS5.1-targeted native adapter: verifies NSSM service ownership, snapshots
running/startup/delayed-start and task-enable state, disables owned startup/tasks while
fenced, stops producers/access, checks remaining owned children, then restores recorded
state. All eight services use a bundled Python startup gate; the Caddy service SID and
LocalService/LocalSystem distinctions remain. Protected metadata grants only read access;
coordination/spool grants application writes, without exposing the spool to Caddy's SID.
Python script paths are explicit for the Windows embedded interpreter's isolated imports.

Mac validates owned LaunchDaemon plists, bootouts producers/access, checks loaded jobs
and owned runtime/standalone writers, and bootstraps only recorded roles after checks.
The launchd child supervisor parks before logs/children. Metadata is root-owned/group-read;
coordination/spool are group-writable with setgid directories. D4's private web/TLS island
is excluded from recursive app-state ownership changes. Caddy remains root-run as D4
specified; PHP/live/detector/scheduler retain `_philcstvms`, Devices its existing root role.

D4 configure uses Enter/Resume and owned prepare verification; failure leaves a closed
state and retained delivery. Its ordinary config rollback is still not D5B power-loss
recovery. Canonical HTTPS, explicit LAN mode, loopback/key integration, separate TLS/update
trust, private media and the leased go2rtc derivative are preserved. No new client collector,
Update button, background update checking/downloading or publication was introduced.

## 6. Complete D5A changed files and schema

Relative to the captured D5A starting workspace; earlier unrelated changes retained.

Modified existing files:

```text
app/Http/Middleware/FinalizePendingScans.php
app/Providers/AppServiceProvider.php
app/Services/BackupService.php
app/Services/CrossingSnapshotService.php
app/Services/SystemResetService.php
bootstrap/app.php
build/macos/OFFLINE-INSTALL.md
build/macos/build.py
build/macos/templates/php.ini.template
build/windows/OFFLINE-INSTALL.md
build/windows/assemble.ps1
build/windows/package.py
build/windows/templates/php.ini.template
deploy/macos/control.py
deploy/macos/service_runner.py
deploy/network/NETWORK-SETUP.md
deploy/network/server.py
deploy/windows/backup-now.ps1
deploy/windows/install-package.ps1
deploy/windows/install.ps1
deploy/windows/lib/common.ps1
deploy/windows/lib/daily-backup.cmd
deploy/windows/lib/scheduler.cmd
deploy/windows/restore.ps1
deploy/windows/uninstall.ps1
deploy/windows/update.ps1
routes/api.php
routes/console.php
school-vehicle-monitoring-detector/camera_service.py
school-vehicle-monitoring-detector/detector_service.py
school-vehicle-monitoring-detector/device_service.py
school-vehicle-monitoring-detector/devices/reader_link.py
school-vehicle-monitoring-detector/laravel_client.py
tests/Feature/DetectionPipelineStatusTest.php
tests/deployment/test_package.py
tests/network/test_policy.py
```

Added files:

```text
DEPLOYMENT-D5A.md
app/Http/Controllers/Api/DeliveryIntegrationController.php
app/Http/Middleware/LifecycleWriterBarrier.php
app/Services/DeliveryMediaJournal.php
app/Services/LegacyLifecycleGuard.php
app/Services/LifecycleBarrier.php
config/lifecycle.php
database/migrations/2026_11_09_000001_create_delivery_receipts.php
deploy/lifecycle/MAINTENANCE.md
deploy/lifecycle/coordination.py
deploy/lifecycle/maintenance.py
deploy/lifecycle/service_host.py
deploy/windows/lifecycle-adapter.ps1
school-vehicle-monitoring-detector/durable_delivery.py
tests/Feature/DurableDeliveryTest.php
tests/fixtures/d5a-runtime.php
tests/lifecycle/http_delivery.py
tests/lifecycle/test_delivery.py
tests/windows/lifecycle.tests.ps1
```

The additive migration creates `delivery_installations` (one installation/generation
binding) and `delivery_receipts` (operation primary key, SHA, identity, kind, result JSON,
commit time). It changes no existing record/table. It was run only in private fixtures.
Fresh installers bind identity after schema creation; existing-install migration/promotion
remains D5C, not an upgrade instruction for Windows today. PHP package post size increases
25→32 MiB to accommodate two existing 10 MiB image limits after lossless base64 transport;
individual image validation is unchanged. Both builders include the maintenance guide;
source capture excludes lifecycle/private installation state.

## 7. Tests actually run and results

| Check | Executed result |
| --- | --- |
| Full isolated Laravel, host PHP8.5.3 | **837 tests, 8,815 assertions passed**; includes 12 new delivery/fence regressions |
| Durable queue/maintenance fixtures | **31 passed**: process kill/restart, uncommitted media rollback, corruption retention, generation refusal, bounded capture/ACKs, claims/tokens/dependencies, auth/payload failures, original scan identity, recovery/overlap, producer grace/review, PHP transaction fence, restart gates, D4 orchestration and Mac shortcut refusal |
| Real end-to-end synthetic delivery | Actual `LaravelEventClient` + TagPoster + outbox + requests against **four private PHP workers**, real migrations/controllers/database/cache/media; unavailable server then recovery, commit/lost ACK, six concurrent retries, later plate evidence, original context, maintenance racing an open application transaction, reassigned context refusal and generation/key refusal passed |
| Detector Python regression suite | **185 run: 184 passed, 1 skipped** with packaged arm64 Python3.12; skip is local `yolov8s.pt` model-metadata availability, not loosened assertions |
| Shared D2 package / Mac policy | **14 + 19 passed** |
| D3 update contracts | **29 passed**, ephemeral fixture signing keys only; no production trust/change |
| Network policy / detector lease fixtures | **17 passed**; source and fake/native-boundary fixtures, not privileged LAN changes |
| Windows common / preflight helpers | **17 + 13 passed**, macOS PowerShell7 |
| New Windows D5A pure helper checks | **30 passed**: actual service definitions/identities, startup/stop bypass refusals and missing-state detection; no native service action |
| PowerShell parser | **45 source/build/test scripts parsed**, no syntax errors; PS5.1 native execution NOT RUN |
| JavaScript regression | **17 passed**; no frontend source changes in D5A |
| Python syntax | **67 relevant source files parsed**; isolated import entrypoints also tested with Python `-I` |
| Preservation/package identity | Working `.env` and staged index unchanged; existing full archive and D4 helper ZIP hashes unchanged; no source deletion |

The real-helper fixture retained **3 receipts, 1 crossing, 1 canonical event and 1 RFID
scan** after initial crossing/plate/RFID delivery and concurrent/repeated retry. It compared
occupancy/state/entry counters and alert counts before/after retries and checked original
snapshot SHA, time/key/camera. A later genuinely separate pass remained separate. PHPUnit
also verified staff plate correction permanence, archived gate handling, wrong camera refusal,
malformed envelope/media, receipt-insert rollback/media cleanup, maintenance authorization
and scheduler refusal. Existing full-suite tests retain plate, optional RFID, lost-UID,
chronology, accounts, reports and evidence rules; none was relaxed for delivery.

Earlier fixture failures were corrected and rerun: private copy lacked Git/stock relay ZIPs
and a vendor data file; Laravel testing suppressed console events until lifecycle bootstrap
explicitly rerouted them; a synthetic capture time was in the future; the first Python harness
overrode the conventional YOLO settings path. That first detector run also downloaded its
missing model **into the private copy**. The successful rerun used the existing pinned package
model and offline/CPU flags. WAN was not physically disconnected; no clean offline-target
acceptance follows from those results. No model or live configuration was replaced.

Browser DOM/UI, frontend rebuild, D4 high-port Caddy certificate/leaf renewal and live camera
inference were not rerun for this batch; D4's results remain historical. The real HTTP fixture
is not machine-trusted Chrome HTTPS or a physical reader/camera test. No application UI layout
changed. Loaded multi-browser/video performance is unverified.

## 8. Unresolved native/deployment blockers

**NOT RUN:** Windows services/NSSM/SCM/ACL/task behavior on native PS5.1; Mac sudo/launchd/
ownership/Keychain/firewall installation; Intel execution; real restart/reboot or power loss;
full-disk hardware faults; physical RFID/camera/video load; multi-computer ownership; native
offline install/repair; production signing/notarization and D3 maintenance/redistribution gates.

The global writer reservation deliberately serializes application requests/commands. Native
throughput and latency acceptance is required. A local fence does not stop another PC owning
the same physical devices. Existing time-window, eligibility and identity-conflict rules can
still send genuinely stale/conflicting evidence to Review or a retained delivery failure.
No broad historical correction or deadline/identity relaxation was performed.

Process-kill tests certify only their observed transaction boundaries. Filesystem/database
atomicity, server crash orphan reconciliation, coherent backups, key/epoch rollback protection,
recovery after interrupted provisioning and promotion remain D5B–D5D. Protected metadata
corruption requires an explicitly validated recovery procedure; deleting state is not one.
The legacy backup task is intentionally unavailable until D5B, not advertised as successful.

## 9. Package/source validity and preservation

Base commit remains `d0e0d93d2a7193b956735c5c58a234b945d1a3f1`; this is an **uncommitted
D5A source snapshot**. No new complete platform archive was built. Disk was inspected
before private copies/build checks (about3.3 GiB initially, about6.3 GiB at later checks);
previous packages/user data were not deleted. Complete native build/acceptance remains a
separate requirement, including Windows assembly unavailable on this host.

The following archive identities were rehashed and remain unchanged:

| Candidate | Bytes | SHA-256 |
| --- | ---: | --- |
| Windows D2 `0.1.0-d2.1-dev.092593dd1048` ZIP | 576,413,086 | `4153ad831042640144f8909177de4bf0fd5dcafcb22b608121b42e67850552dc` |
| Mac arm64 D3 `0.1.0-d3.1-dev.66c18ba2ecda` tar.gz | 497,589,601 | `01cdbcdf56d24d8ed843b401e3fba5f097411737be56aa2fb700dee797677a80` |
| Mac Intel D3 same version tar.gz | 618,013,482 | `e92ea889aa1222ccb0b54bdf1a046c4cff0ea46821851f81e302bb668861de95` |

Their paths/source identities remain in D2/D3. They contain **neither D4 nor D5A** and are
not a common-source release matrix. Existing unsigned D3 update sidecars retain their old
source/archive binding and empty approved-upgrade origins. They are not D5A update packages.
The two D4 client-only ZIPs also retain their recorded hashes; server code changed, client
helpers did not. D5A changes source/build inputs and PHP configuration, so future complete
builds must receive new source/content/update identities. No old identity was relabeled.

The working DB was not used or mutated as a test target. Existing development services were
not stopped; ongoing live processes prevent asserting byte-for-byte DB preservation. No live
migration/reset/reseed/repair or intentional event mutation was performed. The `.env` and
staged index comparison passed; no unrelated captured source was overwritten or deleted.

## 10. HANDOFF FOR D5B, D5C AND D5D

**D5B — coherent backup/restore/recovery:** own and hold the maintenance operation, verify
the stopped writer cut, and explicitly accept/preserve `quiescent_pending`. Copy/validate
as one coherent set:

- Main DB, including `delivery_installations`, all canonical `delivery_receipts`, occurrence
  relationships, occupancy/baselines, audits/accounts/setup and permanent lost-UID/security state.
- Full lifecycle directory: protected identity/generation, phase/build/secret bindings, owner
  lock when held, service/task receipts, producer registrations, coordination DB and outbox DB
  with required journals. Retain original unacknowledged media even without an app row.
- All referenced/private/historical media, gate names/IDs, device assignments, calibration,
  runtime/configuration state and relevant cache/claim state; reconcile server orphan files
  without guessing ownership or deleting pending spool evidence.
- `.env`/APP_KEY/API secrets and decryption viability; **complete D4 private TLS issuer,
  root identity/public handoff, exposure/network receipts/ACLs**; separately protected update
  verifier/trust/rotation state; source/runtime/schema/installation markers and permissions.

Validate manifests, SQLite integrity/schema, decryption/media and replay identities before
any reopening. Design protected generation transitions and monotonic lost-UID/revocation
state reconciliation for older snapshots. Never silently replace keys/identity, reset/reseed,
or revive the old backup/restore merely because Enter exists. Define crash recovery at every
copy/promotion/failure boundary and test restore viability, pending work and duplicate effects.

**D5C — online/offline updates:** preserve the D3 manifest/signature/channel/version/OS/arch/
compatibility contract and D4 trust separation. Provision trusted verification material through
an authenticated installation; downloads cannot supply their own root. Implement bounded optional
discovery/downloads and the same USB/local validator, then admin approval of a concrete update/
backup/recovery plan. Certify migration/outbox/receipt schema compatibility on copies, atomically
promote complete source/runtime/data state and gate restart on validation. Test interrupted
download/migration/swap/rollback, trust rollback/revocation, missing secrets and power/reboot faults.
D5A's legacy guards must not be removed before those transactions exist.

**D5D — transfer:** prove one active installation/collector owner across machines; local PID/SQLite
locks are insufficient. Preserve or deliberately rotate the validated generation/outbox handoff,
TLS issuer and separate update trust. Move canonical DNS/owned mappings/exposure deliberately,
refresh clients, preserve receipts/audit/deny state and prevent duplicate collectors/occurrences.
No transfer or cross-PC lease/epoch authority is provided by D5A.

After reviewed source approval, rebuild one same-source/version Windows/Intel/arm64 set and repeat
native minimum/current OS, offline installation, privileges, restart/reboot, hardware and failure
acceptance. No production release approval follows from these fixtures.

Evidence pointer: `/tmp/philcst-deployment-d5a-workspace.txt`. It identifies private source/DB/media
fixtures and logs including `laravel-full-final.txt/xml`, `lifecycle-final.log`,
`http-delivery-final.log` plus fixture `results.json`, `python-detector-passed.log`,
`package-final.log`, `mac-final.log`, `updates-final.log`, `network-final.log`,
`ps-*-final.log`, `js-regression.log`, `preserved-artifacts.json` and preservation comparison.
These are local evidence, not installation backups or production trust material.

**D5A source/fixture implementation complete. Stop and wait for “Go Deployment D5B.”**
